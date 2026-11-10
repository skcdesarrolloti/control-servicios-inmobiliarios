<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

use SCM\Core\Database;

/**
 * Sugerencias del formulario aprendidas de los inventarios ya hechos: qué elementos se registran en cada sección,
 * con qué material y qué cantidad. Se calculan máximo una vez por semana y quedan en un archivo.
 */
final class InventorySuggestions
{
  private const SAMPLE = 150;
  private const MAX_AGE = 604800;
  private const TOP_MATERIALS = 6;

  public function __construct(private Database $db, private string $cacheFile)
  {
  }

  /** @return array<string,array{items:list<string>,materials:array<string,list<string>>,quantities:array<string,string>}> */
  public function all(): array
  {
    if (is_file($this->cacheFile) && filemtime($this->cacheFile) > time() - self::MAX_AGE) {
      $cached = json_decode((string) file_get_contents($this->cacheFile), true);
      if (is_array($cached)) return $cached;
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

  /** @return array<string,array{items:list<string>,materials:array<string,list<string>>,quantities:array<string,string>}> */
  public function build(): array
  {
    $sections = InventorySchema::sections();
    $columns = implode(', ', array_map(static fn(string $key): string => "`{$key}`", array_keys($sections)));
    $rows = $this->db->getResults("SELECT {$columns} FROM `{$this->db->table('jet_cct_inventario')}` WHERE COALESCE(`cct_status`, 'publish') = 'publish' AND `tipo_inventario` = ? ORDER BY `_ID` DESC LIMIT " . self::SAMPLE, [InventorySchema::NEW_TYPE]);
    return self::aggregate($rows, $sections);
  }

  /**
   * @param list<array<string,mixed>> $rows @param array<string,array<string,mixed>> $sections
   * @return array<string,array{items:list<string>,materials:array<string,list<string>>,quantities:array<string,string>}>
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
      foreach ($items as $name => $data) {
        $found = $data['m'] ?? [];
        arsort($found);
        $materials[$name] = array_map('strval', array_slice(array_keys($found), 0, self::TOP_MATERIALS));
        $quantity = $data['q'] ?? [];
        arsort($quantity);
        $quantities[$name] = (string) (array_key_first($quantity) ?? '');
      }
      $out[$bucket] = ['items' => array_map('strval', array_keys($items)), 'materials' => $materials, 'quantities' => $quantities];
    }
    return $out;
  }

  private static function label(string $value): string
  {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return $value === '' ? '' : mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
  }
}
