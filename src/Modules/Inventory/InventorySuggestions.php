<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

use SCM\Core\Database;

/**
 * Sugerencias del formulario aprendidas de los inventarios ya hechos: qué elementos se registran en cada sección,
 * con qué material, qué cantidad y qué observaciones. Se calculan máximo una vez por semana y quedan en un archivo.
 */
final class InventorySuggestions
{
  private const SAMPLE = 150;
  private const MAX_AGE = 604800;
  private const TOP_MATERIALS = 6;
  private const TOP_OBSERVATIONS = 8;
  private const MIN_OBSERVATION_USES = 2;
  /** Observaciones que no aportan como sugerencia (ya es el valor por defecto o no dicen nada). */
  private const EMPTY_OBSERVATIONS = ['ninguna', 'ninguno', 'n/a', 'na', 'no aplica', 'ok', 'bien', 'sin observaciones', 'sin observación', 'sin observacion', '-', '.', '0'];

  public function __construct(private Database $db, private string $cacheFile)
  {
  }

  /** @return array<string,array{items:list<string>,materials:array<string,list<string>>,quantities:array<string,string>,observations:array<string,list<string>>}> */
  public function all(): array
  {
    if (is_file($this->cacheFile) && filemtime($this->cacheFile) > time() - self::MAX_AGE) {
      $cached = json_decode((string) file_get_contents($this->cacheFile), true);
      // Un archivo anterior a las observaciones aprendidas se vuelve a calcular.
      $current = is_array($cached) && array_filter($cached, static fn($bucket): bool => !is_array($bucket) || !array_key_exists('observations', $bucket)) === [];
      if ($current) return $cached;
    }
    try {
      $result = $this->build();
      if (!is_dir(dirname($this->cacheFile))) @mkdir(dirname($this->cacheFile), 0775, true);
      @file_put_contents($this->cacheFile, (string) json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
      return $result;
    } catch (\Throwable $error) {
      error_log('[inventario-sugerencias] ' . $error->getMessage());
      return [];
    }
  }

  /** @return array<string,array{items:list<string>,materials:array<string,list<string>>,quantities:array<string,string>,observations:array<string,list<string>>}> */
  public function build(): array
  {
    $sections = InventorySchema::sections();
    $columns = implode(', ', array_map(static fn(string $key): string => "`{$key}`", array_keys($sections)));
    $rows = $this->db->getResults("SELECT {$columns} FROM `{$this->db->table('jet_cct_inventario')}` WHERE COALESCE(`cct_status`, 'publish') = 'publish' AND `tipo_inventario` = ? ORDER BY `_ID` DESC LIMIT " . self::SAMPLE, [InventorySchema::NEW_TYPE]);
    return self::aggregate($rows, $sections);
  }

  /**
   * @param list<array<string,mixed>> $rows @param array<string,array<string,mixed>> $sections
   * @return array<string,array{items:list<string>,materials:array<string,list<string>>,quantities:array<string,string>,observations:array<string,list<string>>}>
   */
  public static function aggregate(array $rows, array $sections): array
  {
    $counts = [];
    foreach ($rows as $row) {
      foreach ($sections as $key => $section) {
        // Las habitaciones y los baños comparten lo aprendido entre sí.
        $bucket = $section['group'] === 'general' ? $key : $section['group'];
        foreach (InventorySchema::rowsFromStored($row[$key] ?? '', (string) $section['suffix']) as $item) {
          $name = self::label($item['d']);
          if ($name === '') continue;
          $counts[$bucket][$name]['n'] = ($counts[$bucket][$name]['n'] ?? 0) + 1;
          $material = self::label($item['m']);
          if ($material !== '' && mb_strlen($material, 'UTF-8') <= 60) {
            $counts[$bucket][$name]['m'][$material] = ($counts[$bucket][$name]['m'][$material] ?? 0) + 1;
          }
          $observation = self::label($item['o']);
          if ($observation !== '' && mb_strlen($observation, 'UTF-8') <= 80 && !in_array(mb_strtolower($observation, 'UTF-8'), self::EMPTY_OBSERVATIONS, true)) {
            $counts[$bucket][$name]['o'][$observation] = ($counts[$bucket][$name]['o'][$observation] ?? 0) + 1;
          }
          if ($item['c'] !== '') {
            $counts[$bucket][$name]['q'][$item['c']] = ($counts[$bucket][$name]['q'][$item['c']] ?? 0) + 1;
          }
        }
      }
    }
    $out = [];
    foreach ($counts as $bucket => $items) {
      uasort($items, static fn(array $a, array $b): int => $b['n'] <=> $a['n']);
      $materials = [];
      $quantities = [];
      $observations = [];
      foreach ($items as $name => $data) {
        // Solo las que se repiten: así no se sugieren errores de digitación de un solo inventario.
        $notes = array_filter($data['o'] ?? [], static fn(int $times): bool => $times >= self::MIN_OBSERVATION_USES);
        arsort($notes);
        $kept = self::distinct(array_map('strval', array_keys($notes)), self::TOP_OBSERVATIONS);
        if ($kept !== []) {
          $observations[$name] = $kept;
        }
        $found = $data['m'] ?? [];
        arsort($found);
        $materials[$name] = self::distinct(array_map('strval', array_keys($found)), self::TOP_MATERIALS);
        $quantity = $data['q'] ?? [];
        arsort($quantity);
        $quantities[$name] = (string) (array_key_first($quantity) ?? '');
      }
      $out[$bucket] = ['items' => array_map('strval', array_keys($items)), 'materials' => $materials, 'quantities' => $quantities, 'observations' => $observations];
    }
    return $out;
  }

  /**
   * Quita variantes casi iguales a una más usada ("probado"/"porbado", "Presenta"/"Presentan").
   * @param list<string> $values ordenadas de más a menos usadas @return list<string>
   */
  private static function distinct(array $values, int $limit): array
  {
    $kept = [];
    $keys = [];
    foreach ($values as $value) {
      $key = self::comparable($value);
      foreach ($keys as $other) {
        // Con números distintos ("1 rejilla" / "2 rejillas") son observaciones diferentes.
        if (preg_replace('/\D+/', ' ', $key) !== preg_replace('/\D+/', ' ', $other)) continue;
        if (levenshtein($key, $other) <= max(2, intdiv(min(strlen($key), strlen($other)), 12))) continue 2;
      }
      $kept[] = $value;
      $keys[] = $key;
      if (count($kept) >= $limit) break;
    }
    return $kept;
  }

  private static function comparable(string $value): string
  {
    $value = mb_strtolower($value, 'UTF-8');
    $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return substr(trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? ''), 0, 255);
  }

  private static function label(string $value): string
  {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return $value === '' ? '' : mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
  }
}
