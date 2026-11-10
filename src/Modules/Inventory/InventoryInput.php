<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

use SCM\Modules\DeliveryReview\DeliveryReviewForm;

/** Valida el JSON del formulario y lo convierte en columnas del CCT `inventario`. */
final class InventoryInput
{
  /** Campos que se copian tal cual. */
  private const TEXT_FIELDS = ['contrato', 'inmueble', 'direccion', 'tipo_de_inmueble', 'tipo_negocio', 'destinacion', 'registro_fotografico', 'arrendatario', 'cedula_arrendatario', 'correo_arrendatario', 'celular_arrendatario'];
  private const STATES = ['Bueno', 'Regular', 'Malo'];

  /**
   * @param array<string,mixed> $payload
   * @param array{types:list<string>,categories:list<string>} $options
   * @return array{columns:array<string,string|int>,with_transport:bool,services:list<string>}
   */
  public static function parse(array $payload, array $options, bool $requirePhotos): array
  {
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $text = static fn(string $key, int $max = 500): string => self::clean($data[$key] ?? '', $max);
    $missing = static function (string $label): never {
      throw new \DomainException('Falta completar: ' . $label . '.');
    };

    $type = $text('tipo_inventario');
    if (!in_array($type, $options['types'], true)) $missing('tipo de inventario');
    $category = $text('categoria_inventario');
    if (!in_array($category, $options['categories'], true)) $missing('categoría de inventario');

    $columns = ['tipo_inventario' => $type, 'categoria_inventario' => $category];
    foreach (self::TEXT_FIELDS as $field) {
      $columns[$field] = $text($field, $field === 'registro_fotografico' ? 1000 : 500);
    }
    foreach (['contrato' => 'contrato', 'inmueble' => 'inmueble', 'direccion' => 'dirección', 'arrendatario' => 'arrendatario', 'correo_arrendatario' => 'correo del arrendatario', 'celular_arrendatario' => 'celular del arrendatario'] as $field => $label) {
      if ($columns[$field] === '') $missing($label);
    }
    if ($requirePhotos && !preg_match('#^https?://#i', (string) $columns['registro_fotografico'])) {
      $missing('enlace del registro fotográfico');
    }

    $columns += self::conditional($text, $missing);

    $boundaryTs = strtotime($text('fecha_linderos') . ' 12:00:00') ?: 0;
    if ($boundaryTs <= 0) $missing('fecha de toma de linderos');
    $columns['fecha_linderos'] = $boundaryTs;
    $columns += self::boundaries(is_array($payload['boundaries'] ?? null) ? $payload['boundaries'] : [], $columns, $missing);

    $floors = InventorySchema::floors($text('cantidad_pisos'));
    $rooms = max(0, min(InventorySchema::MAX_ROOMS, (int) $text('cantidad_habitaciones')));
    $baths = max(0, min(InventorySchema::MAX_ROOMS, (int) $text('cantidad_banos')));
    $columns['cantidad_pisos'] = (string) $floors;
    $columns['cantidad_habitaciones'] = $rooms > 0 ? (string) $rooms : '';
    $columns['cantidad_banos'] = $baths > 0 ? (string) $baths : '';

    $furnished = strcasecmp($category, InventorySchema::FURNISHED) === 0;
    $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
    $furniture = is_array($payload['furniture'] ?? null) ? $payload['furniture'] : [];
    $total = 0;
    foreach (InventorySchema::sections() as $key => $section) {
      $visible = $section['group'] === 'general'
        || ($section['group'] === 'habitacion' && $section['index'] <= $rooms)
        || ($section['group'] === 'bano' && $section['index'] <= $baths);
      $entry = $visible && is_array($sections[$key] ?? null) ? $sections[$key] : [];
      $rows = self::rows($entry['rows'] ?? [], true);
      $total += count($rows);
      $columns[$key] = InventorySchema::rowsToStored($rows, $section['suffix']);
      if ($section['floor'] !== '') {
        $columns[$section['floor']] = self::clean($entry['floor'] ?? '', 60);
        $columns[$section['details']] = self::clean($entry['details'] ?? '', 2000);
      }
      $furnitureRows = $furnished && $visible ? self::rows($furniture[$key] ?? [], false) : [];
      $columns[$section['furniture']] = InventorySchema::rowsToStored($furnitureRows, $section['furniture_suffix'], false);
    }
    if ($total === 0) {
      throw new \DomainException('Registra al menos un elemento en el inventario.');
    }

    $columns['observaciones'] = self::clean($data['observaciones'] ?? '', 5000) ?: 'Ninguna';
    $services = self::list($data['servicios'] ?? [], ['Agua', 'Gas', 'Energia']);
    $columns['servicios_publicos'] = serialize($services);
    foreach ([['Energia', 'luz', 'medidor_luz', 'luz'], ['Agua', 'agua', 'medidor_agua', 'agua'], ['Gas', 'gas', 'medidor_gas', 'gas']] as [$service, $policy, $meter, $label]) {
      $active = in_array($service, $services, true);
      $columns[$policy] = $active ? $text($policy, 100) : '';
      $columns[$meter] = $active ? $text($meter, 100) : '';
      if ($active && $columns[$policy] === '') $missing('póliza o cuenta de ' . $label);
    }
    $agreements = self::list($data['acuerdos'] ?? [], array_keys(InventorySchema::agreements()));
    $columns['acuerdos_precontractuales'] = serialize($agreements);
    foreach (InventorySchema::agreements() as $label => $column) {
      $columns[$column] = in_array($label, $agreements, true) ? self::clean($data[$column] ?? '', 5000) : '';
    }

    return [
      'columns' => $columns,
      'with_transport' => $type === InventorySchema::NEW_TYPE && strcasecmp($text('con_transporte'), 'Si') === 0,
      'services' => $services,
    ];
  }

  /**
   * @param callable(string,int=):string $text
   * @param callable(string):never $missing
   * @return array<string,string>
   */
  private static function conditional(callable $text, callable $missing): array
  {
    $yes = static fn(string $value): bool => strcasecmp($value, 'Si') === 0;
    $choice = static function (string $key, string $label) use ($text, $missing): string {
      $value = $text($key);
      if (!in_array($value, ['Si', 'No'], true)) $missing($label);
      return $value;
    };
    $parking = $choice('tiene_parqueadero', '¿tiene parqueadero?');
    $storage = $choice('tiene_deposito', '¿tiene depósito?');
    $delegate = $choice('tiene_delegado', '¿tiene delegado?');
    $out = ['tiene_parqueadero' => $parking, 'tiene_deposito' => $storage, 'tiene_delegado' => $delegate];
    foreach (['tipo_parqueadero' => 'tipo de parqueadero', 'parqueaderos' => 'cantidad de parqueaderos', 'numero_parqueadero' => 'número de parqueadero'] as $key => $label) {
      $out[$key] = $yes($parking) ? $text($key) : '';
      if ($yes($parking) && $out[$key] === '') $missing($label);
    }
    $out['depositos'] = $yes($storage) ? $text('depositos') : '';
    if ($yes($storage) && $out['depositos'] === '') $missing('cantidad de depósitos');
    foreach (['delegado' => 'nombre del delegado', 'correo_delegado' => 'correo del delegado', 'celular_delegado' => 'celular del delegado'] as $key => $label) {
      $out[$key] = $yes($delegate) ? $text($key) : '';
      if ($yes($delegate) && $out[$key] === '') $missing($label);
    }
    $out['cedula_delegado'] = $yes($delegate) ? $text('cedula_delegado') : '';
    return $out;
  }

  /**
   * @param array<string,mixed> $raw @param array<string,mixed> $columns @param callable(string):never $missing
   * @return array<string,string>
   */
  private static function boundaries(array $raw, array $columns, callable $missing): array
  {
    $out = [];
    foreach (InventorySchema::boundaries() as $column => $group) {
      $rows = [];
      $active = $group['when'] === '' || strcasecmp((string) ($columns[$group['when']] ?? ''), 'Si') === 0;
      foreach ($active && is_array($raw[$column] ?? null) ? $raw[$column] : [] as $row) {
        if (!is_array($row)) continue;
        $item = [];
        if ($column === 'parqueadero_lin') {
          $item['num_parqueadero'] = self::clean($row['num'] ?? '', 60);
        }
        foreach ($group['sides'] as $side) {
          $item[$side . '_' . $group['suffix']] = self::clean($row[$side] ?? '', 300);
        }
        if (implode('', $item) !== '') $rows[] = $item;
      }
      if ($group['required'] && $rows === []) $missing('linderos del inmueble');
      $out[$column] = DeliveryReviewForm::storeRows($rows);
    }
    return $out;
  }

  /** @return list<array{d:string,c:string,m:string,e:string,o:string}> */
  private static function rows(mixed $raw, bool $material): array
  {
    $rows = [];
    foreach (is_array($raw) ? $raw : [] as $row) {
      if (!is_array($row)) continue;
      $description = self::clean($row['d'] ?? '', 2000);
      if ($description === '') continue;
      $state = self::clean($row['e'] ?? '', 20);
      $rows[] = [
        'd' => $description,
        'c' => self::clean($row['c'] ?? '', 60),
        'm' => $material ? self::clean($row['m'] ?? '', 500) : '',
        'e' => in_array($state, self::STATES, true) ? $state : 'Bueno',
        'o' => self::clean($row['o'] ?? '', 2000) ?: 'Ninguna',
      ];
      if (count($rows) >= InventorySchema::MAX_ROWS) break;
    }
    return $rows;
  }

  /** @param list<string> $allowed @return list<string> */
  private static function list(mixed $raw, array $allowed): array
  {
    $values = array_map(static fn($value): string => self::clean($value, 40), is_array($raw) ? $raw : []);
    return array_values(array_intersect($allowed, $values));
  }

  private static function clean(mixed $value, int $max): string
  {
    if (!is_scalar($value)) return '';
    return mb_substr(trim(strip_tags((string) $value)), 0, $max, 'UTF-8');
  }
}
