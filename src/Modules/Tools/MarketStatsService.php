<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Core\Database;

/**
 * Herramientas › Calculadora de mercado: precio por m² de venta y de arriendo de los inmuebles registrados
 * (antes shortcode [calculadora_estadistica_inmuebles] sobre la API REST de JetEngine). Consulta la tabla
 * directamente: sin el tope de 2.000 registros y con los Sí/No guardados como listas serializadas.
 */
final class MarketStatsService
{
  /** Estados que entran por defecto: oferta vigente y cierres reales; quedan fuera borradores, desistidos y vetados. */
  public const DEFAULT_STATES = ['Publico', 'Arrendado', 'Vendido', 'Ocupado', 'En cierre', 'En proceso de cierre'];
  private const YES_NO_FIELDS = ['parqueadero', 'deposito', 'vigilancia'];
  private const BUILT_AREA_TYPES = ['casa', 'oficina', 'local', 'bodega', 'apartaestudio', 'consultorio', 'edificio', 'finca', 'hotel'];
  private const LAND_AREA_TYPES = ['lote', 'casa lote'];

  public function __construct(private Database $db) {}

  /** @return array{rutas:array<string,list<string>>,barrios:list<array{barrio:string,ruta:string}>,tipos:list<string>,destinaciones:list<string>,estratos:list<string>,estados:list<array{value:string,count:int,default:bool}>} */
  public function options(): array
  {
    $barrios = [];
    $rutas = [];
    foreach ($this->db->getResults('SELECT barrio, ruta_asignada FROM `' . $this->db->table('jet_cct_barrios') . "` WHERE TRIM(COALESCE(barrio, '')) <> '' ORDER BY barrio") as $row) {
      $barrio = trim((string) $row['barrio']);
      $ruta = trim((string) ($row['ruta_asignada'] ?? ''));
      $barrios[] = ['barrio' => $barrio, 'ruta' => $ruta];
      if ($ruta !== '') $rutas[$ruta][] = $barrio;
    }
    uksort($rutas, static fn(string $a, string $b): int => strnatcmp($a, $b));

    $distinct = function (string $column, int $minimum = 1): array {
      $rows = $this->db->getResults("SELECT TRIM(`{$column}`) AS v, COUNT(*) AS n FROM `" . $this->table() . "` WHERE TRIM(COALESCE(`{$column}`, '')) <> '' GROUP BY TRIM(`{$column}`) HAVING n >= " . max(1, $minimum) . ' ORDER BY v');
      return array_map(static fn(array $row): array => ['value' => (string) $row['v'], 'count' => (int) $row['n']], $rows);
    };
    // Estrato tiene algunos códigos catastrales escritos por error: solo valores cortos y repetidos.
    $estratos = array_values(array_filter(array_column($distinct('estrato', 2), 'value'), static fn(string $v): bool => mb_strlen($v) <= 12));
    usort($estratos, static fn(string $a, string $b): int => [!ctype_digit($a), $a] <=> [!ctype_digit($b), $b]);

    return [
      'rutas' => $rutas,
      'barrios' => $barrios,
      'tipos' => array_column($distinct('tipo_inmueble'), 'value'),
      'destinaciones' => array_column($distinct('destinacion'), 'value'),
      'estratos' => $estratos,
      'estados' => array_map(static fn(array $row): array => $row + ['default' => in_array($row['value'], self::DEFAULT_STATES, true)], $distinct('estado')),
    ];
  }

  /**
   * @param array<string,mixed> $filters
   * @return array{venta:list<float>,arriendo:list<float>,items:list<array{id:int,codigo:string,barrio:string,tipo:string,estado:string,modo:string,precio:float,area:float,m2:float}>,without_area:int}
   */
  public function analyze(array $filters): array
  {
    $where = ["cct_status = 'publish'"];
    $args = [];
    foreach (['tipo_inmueble', 'destinacion', 'estrato'] as $field) {
      $value = trim((string) ($filters[$field] ?? ''));
      if ($value !== '') {
        $where[] = "TRIM(`{$field}`) = ?";
        $args[] = $value;
      }
    }
    $barrio = trim((string) ($filters['barrio'] ?? ''));
    $ruta = trim((string) ($filters['ruta'] ?? ''));
    if ($barrio !== '') {
      $where[] = 'TRIM(barrio) = ?';
      $args[] = $barrio;
    } elseif ($ruta !== '') {
      $neighborhoods = $this->options()['rutas'][$ruta] ?? [];
      if ($neighborhoods === []) return ['venta' => [], 'arriendo' => [], 'items' => [], 'without_area' => 0];
      $where[] = 'TRIM(barrio) IN (' . implode(',', array_fill(0, count($neighborhoods), '?')) . ')';
      array_push($args, ...$neighborhoods);
    }
    $rooms = (int) ($filters['habitaciones'] ?? 0);
    if ($rooms > 0) {
      $where[] = 'CAST(NULLIF(TRIM(habitaciones), \'\') AS UNSIGNED) = ?';
      $args[] = $rooms;
    }
    $age = trim((string) ($filters['edad'] ?? ''));
    if ($age !== '' && is_numeric($age)) {
      $where[] = "TRIM(edad) <> '' AND CAST(TRIM(edad) AS DECIMAL(10,2)) <= ?";
      $args[] = (float) $age;
    }
    foreach (self::YES_NO_FIELDS as $field) {
      $value = (string) ($filters[$field] ?? '');
      if (in_array($value, ['Si', 'No'], true)) {
        // JetEngine guarda los checkbox como lista serializada (a:1:{i:0;s:2:"Si";}); algunos registros viejos, en texto.
        $where[] = "(TRIM(`{$field}`) = ? OR `{$field}` LIKE ?)";
        array_push($args, $value, '%"' . $value . '"%');
      }
    }
    $states = array_values(array_filter(array_map('strval', (array) ($filters['estados'] ?? self::DEFAULT_STATES)), static fn(string $v): bool => trim($v) !== ''));
    if ($states !== []) {
      $where[] = 'TRIM(estado) IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
      array_push($args, ...$states);
    }

    $rows = $this->db->getResults(
      'SELECT _ID, codigo, barrio, tipo_inmueble, tipo_negocio, estado, precio_venta, precio_arriendo, area_construida, area_privada, area_terreno FROM `' . $this->table() . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY _ID DESC',
      $args
    );

    $category = (string) ($filters['categoria'] ?? '');
    $out = ['venta' => [], 'arriendo' => [], 'items' => [], 'without_area' => 0];
    foreach ($rows as $row) {
      $area = self::area($row);
      if ($area <= 0) {
        $out['without_area']++;
        continue;
      }
      foreach (['Venta' => 'precio_venta', 'Arriendo' => 'precio_arriendo'] as $mode => $priceField) {
        if ($category !== '' && $category !== $mode) continue;
        $price = self::number($row[$priceField] ?? '');
        if ($price <= 0) continue;
        $perMeter = $price / $area;
        $out[strtolower($mode)][] = $perMeter;
        $out['items'][] = [
          'id' => (int) $row['_ID'],
          'codigo' => trim((string) ($row['codigo'] ?? '')),
          'barrio' => trim((string) ($row['barrio'] ?? '')),
          'tipo' => trim((string) ($row['tipo_inmueble'] ?? '')),
          'estado' => trim((string) ($row['estado'] ?? '')),
          'modo' => $mode,
          'precio' => $price,
          'area' => $area,
          'm2' => $perMeter,
        ];
      }
    }
    return $out;
  }

  /**
   * Área que se usa por tipo (regla del shortcode original); un apartamento sin área privada usa la construida.
   * @param array<string,mixed> $row
   */
  public static function area(array $row): float
  {
    $type = mb_strtolower(trim((string) ($row['tipo_inmueble'] ?? '')), 'UTF-8');
    if (in_array($type, self::BUILT_AREA_TYPES, true)) return self::number($row['area_construida'] ?? '');
    if ($type === 'apartamento') {
      $private = self::number($row['area_privada'] ?? '');
      return $private > 0 ? $private : self::number($row['area_construida'] ?? '');
    }
    if (in_array($type, self::LAND_AREA_TYPES, true)) return self::number($row['area_terreno'] ?? '');
    return 0.0;
  }

  /** Números de la tabla: "76", "51.22", "1.500.000" o "51,22". */
  public static function number(mixed $value): float
  {
    $raw = str_replace(['$', ' ', "\u{00A0}"], '', trim((string) $value));
    if ($raw === '') return 0.0;
    $lastComma = strrpos($raw, ',');
    $lastDot = strrpos($raw, '.');
    if ($lastComma !== false && $lastDot !== false) {
      $decimal = $lastComma > $lastDot ? ',' : '.';
      $raw = str_replace($decimal === ',' ? '.' : ',', '', $raw);
      $raw = str_replace(',', '.', $raw);
    } elseif ($lastComma !== false) {
      $raw = substr_count($raw, ',') > 1 ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
    } elseif ($lastDot !== false && substr_count($raw, '.') > 1) {
      $raw = str_replace('.', '', $raw);
    }
    return is_numeric($raw) ? max(0.0, (float) $raw) : 0.0;
  }

  private function table(): string
  {
    return $this->db->table('jet_cct_inmuebles');
  }
}
