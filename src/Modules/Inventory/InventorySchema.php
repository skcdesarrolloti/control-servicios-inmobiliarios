<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

/**
 * Estructura del inventario del inmueble (CCT `inventario`). Usa las mismas columnas y claves de fila que los
 * formularios JetForm "Añadir inventario" y "Editar inventario", así que los inventarios antiguos se ven y se editan igual.
 */
final class InventorySchema
{
  public const MAX_ROOMS = 10;
  public const MAX_FLOORS = 10;
  public const MAX_ROWS = 60;
  public const GLOSSARY_STATE = 673;
  public const GLOSSARY_TYPE = 788;
  public const GLOSSARY_CATEGORY = 787;
  public const GLOSSARY_YES_NO = 175;
  public const GLOSSARY_PARKING = 1011;
  public const GLOSSARY_SERVICES = 1003;
  public const GLOSSARY_AGREEMENTS = 853;
  public const GLOSSARY_ELECTRICAL = 668;
  public const FURNISHED = 'Amoblado';
  public const NEW_TYPE = 'Nuevo';
  /** Inventario de un inmueble ya ocupado (casos de recibo): no se cobra ni va en los anexos. */
  public const PREVIOUS_TYPE = 'Anterior';
  public const ELECTRICAL = 'elementos_electricos';
  /** Clave de fila que marca un elemento eléctrico copiado desde otra sección (se recalcula al guardar). */
  public const ELECTRICAL_SOURCE = 'origen_electricos';
  public const ROOM_TYPES = ['Principal', 'Secundaria', 'Estudio', 'De servicio'];
  public const BATH_TYPES = ['Principal', 'Social', 'Privado', 'Auxiliar', 'De servicio'];
  /** Eléctricos que no están en el glosario 668 pero aparecen en los glosarios de los espacios. */
  private const EXTRA_ELECTRICAL = ['Telefonos', 'Citofonos', 'Interruptores', 'Tomas', 'Rosetas', 'Contador luz', 'Caja de fusibles', 'Lamparas', 'Apliques', 'Plafon', 'Ojo de buey', 'Bombillo', 'Tomacorriente'];

  private const ROOM_WORDS = ['principal', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez'];

  /**
   * Secciones del inventario. `suffix` es el sufijo de las claves de cada fila (descripcion_<suffix>…).
   *
   * @return array<string,array{label:string,group:string,glossary:int,suffix:string,furniture:string,furniture_suffix:string,floor:string,details:string,index:int}>
   */
  public static function sections(): array
  {
    $section = static fn(string $label, string $group, int $glossary, string $suffix, string $furniture, string $furnitureSuffix, string $floor = '', string $details = '', int $index = 0): array => [
      'label' => $label, 'group' => $group, 'glossary' => $glossary, 'suffix' => $suffix, 'furniture' => $furniture,
      'furniture_suffix' => $furnitureSuffix, 'floor' => $floor, 'details' => $details, 'index' => $index,
    ];
    $general = static fn(string $key, string $label, int $glossary): array => $section($label, 'general', $glossary, $key, $key . '_muebles', $key . '_muebles');
    $sections = [
      'balcon' => $general('balcon', 'Balcón', 679),
      'terraza' => $general('terraza', 'Terraza', 679),
      'elementos_principales' => $section('Elementos principales', 'general', 667, 'principales', 'elementos_principales_muebles', 'principales_muebles'),
      'sala' => $general('sala', 'Sala, comedor y hall', 669),
      'cocina_o_cafeteria' => $general('cocina_o_cafeteria', 'Cocina o cafetería', 675),
      'patio' => $general('patio', 'Patio y/o zona de labores', 679),
      'bano_emergencia' => $section('Baño de emergencia', 'general', 674, 'bano_emergencia', 'bano_emergencia_muebles', 'bano_emergencia_muebles', 'piso_bano_emergencia', 'detalles_bano_emergencia'),
    ];
    foreach (self::ROOM_WORDS as $position => $word) {
      $key = 'habitacion_' . $word;
      $sections[$key] = $section('Habitación ' . ($position + 1), 'habitacion', 676, $key, $key . '_muebles', $key . '_muebles', 'piso_' . $key, 'detalles_' . $key, $position + 1);
    }
    foreach (self::ROOM_WORDS as $position => $word) {
      $key = 'bano_' . $word;
      $sections[$key] = $section('Baño ' . ($position + 1), 'bano', 677, $key, $key . '_muebles', $key . '_muebles', 'piso_' . $key, 'detalles_' . $key, $position + 1);
    }
    $sections['garaje'] = $general('garaje', 'Garaje', 678);
    $sections['deposito_inventario'] = $general('deposito_inventario', 'Depósito', 678);
    $sections['bodega'] = $general('bodega', 'Bodega', 678);
    $sections['oficina'] = $general('oficina', 'Oficina', 669);
    $sections[self::ELECTRICAL] = $section('Elementos eléctricos', 'general', self::GLOSSARY_ELECTRICAL, 'electricos', 'elementos_electricos_muebles', 'electricos_muebles');
    $sections['otros'] = $general('otros', 'Otros', 0);
    return $sections;
  }

  /**
   * Linderos: la clave es la columna del inventario y del contrato (iguales en los dos CCT).
   *
   * @return array<string,array{label:string,suffix:string,sides:list<string>,multiple:bool,when:string,required:bool}>
   */
  public static function boundaries(): array
  {
    $four = ['norte', 'sur', 'oriente', 'occidente'];
    $six = [...$four, 'cenit', 'nadir'];
    return [
      'conjunto_lin' => ['label' => 'Conjunto', 'suffix' => 'conjunto', 'sides' => $four, 'multiple' => false, 'when' => '', 'required' => false],
      'torre_lin' => ['label' => 'Torre', 'suffix' => 'torre', 'sides' => $four, 'multiple' => false, 'when' => '', 'required' => false],
      'inmueble_lin' => ['label' => 'Inmueble', 'suffix' => 'inmueble', 'sides' => $six, 'multiple' => false, 'when' => '', 'required' => true],
      'parqueadero_lin' => ['label' => 'Parqueadero', 'suffix' => 'parqueadero', 'sides' => $six, 'multiple' => true, 'when' => 'tiene_parqueadero', 'required' => false],
      'deposito_lin' => ['label' => 'Depósito', 'suffix' => 'deposito', 'sides' => $six, 'multiple' => true, 'when' => 'tiene_deposito', 'required' => false],
    ];
  }

  /** @return array<string,string> */
  public static function agreements(): array
  {
    return [
      'Propietario' => 'acuerdos_precontractuales_pro',
      'Arrendatario' => 'acuerdos_precontractuales_arre',
      'Copropiedad' => 'acuerdos_precontractuales_co',
      'Inmobiliaria' => 'acuerdos_precontractuales_inm',
      'Externo' => 'acuerdos_precontractuales_ext',
    ];
  }

  /**
   * Secciones de cada tipo de inmueble y su orden. "habitaciones" y "banos" se expanden según la cantidad.
   * `requires` oculta una sección mientras la pregunta del paso Datos no sea "Si". Lo que no está en `order` se puede
   * agregar a mano desde el formulario y siempre se muestra si ya tiene filas.
   *
   * @return array{key:string,order:list<string>,labels:array<string,string>,requires:array<string,string>,hidden:list<string>,rooms_label:string,baths_label:string,max_rooms:int,max_baths:int}
   */
  public static function profile(string $propertyType): array
  {
    $type = self::normalize($propertyType);
    $requires = ['garaje' => 'tiene_parqueadero', 'deposito_inventario' => 'tiene_deposito'];
    $residential = ['elementos_principales', 'sala', 'cocina_o_cafeteria', 'patio', 'habitaciones', 'banos', 'garaje', 'deposito_inventario', self::ELECTRICAL, 'otros'];
    $commercial = ['elementos_principales', 'sala', 'cocina_o_cafeteria', 'banos', 'garaje', self::ELECTRICAL];
    $build = static function (string $key, array $order, array $labels = [], int $rooms = self::MAX_ROOMS, int $baths = self::MAX_ROOMS) use ($requires): array {
      $hidden = array_values(array_filter(array_keys(self::sections()), static fn(string $section): bool => self::sections()[$section]['group'] === 'general' && !in_array($section, $order, true)));
      return [
        'key' => $key, 'order' => $order, 'labels' => $labels, 'requires' => $requires, 'hidden' => $hidden,
        'rooms_label' => 'Habitaciones', 'baths_label' => 'Baños', 'max_rooms' => $rooms, 'max_baths' => $baths,
      ];
    };
    $commercialLabels = ['sala' => 'Sala', 'cocina_o_cafeteria' => 'Cocina o cafetería'];
    return match (true) {
      $type === 'casa' || $type === 'casa lote' => $build('casa', ['terraza', ...$residential], ['patio' => 'Patio']),
      $type === 'apartaestudio' => $build('apartaestudio', ['balcon', ...$residential], ['patio' => 'Patio / área de labores'], 1, 2),
      in_array($type, ['local', 'hotel'], true) => $build('local', [...$commercial, 'bodega', 'otros'], $commercialLabels, 0),
      in_array($type, ['oficina', 'consultorio'], true) => $build('oficina', [...$commercial, 'otros'], $commercialLabels, 0),
      $type === 'bodega' => $build('bodega', [...$commercial, 'oficina', 'otros'], $commercialLabels, 0),
      $type === 'lote' => $build('lote', ['elementos_principales', self::ELECTRICAL, 'otros'], ['elementos_principales' => 'Cerramiento y accesos', 'otros' => 'Construcciones y otros elementos'], 0, 1),
      default => $build('apartamento', ['balcon', ...$residential], ['patio' => 'Patio / área de labores']),
    };
  }

  /** Nombre de la sección para el tipo de inmueble. */
  public static function sectionLabel(string $key, array $profile): string
  {
    return (string) ($profile['labels'][$key] ?? self::sections()[$key]['label'] ?? $key);
  }

  /**
   * Título completo: las habitaciones y baños llevan su tipo y, si hay varios pisos, el piso.
   *
   * @param array<string,string> $types
   */
  public static function sectionTitle(string $key, array $profile, array $types, string $floor, int $floors): string
  {
    $section = self::sections()[$key] ?? null;
    $label = self::sectionLabel($key, $profile);
    if ($section === null || $section['group'] === 'general') {
      return $label;
    }
    $type = trim((string) ($types[$key] ?? ''));
    $parts = [$label];
    if ($type !== '') $parts[] = $type;
    if ($floors > 1) $parts[] = 'Piso ' . self::floorNumber($floor);
    return implode(' · ', $parts);
  }

  /** Claves en el orden del perfil, con habitaciones y baños expandidos. @return list<string> */
  public static function orderedKeys(array $profile, int $rooms, int $baths): array
  {
    $keys = [];
    foreach ($profile['order'] as $item) {
      if ($item === 'habitaciones' || $item === 'banos') {
        $group = $item === 'habitaciones' ? 'habitacion' : 'bano';
        $count = $item === 'habitaciones' ? min($rooms, $profile['max_rooms']) : min($baths, $profile['max_baths']);
        foreach (self::sections() as $key => $section) {
          if ($section['group'] === $group && $section['index'] <= $count) $keys[] = $key;
        }
      } else {
        $keys[] = $item;
      }
    }
    return $keys;
  }

  /** Tipo por defecto: la primera es la principal. */
  public static function defaultRoomType(string $group, int $index): string
  {
    return $index === 1 ? 'Principal' : ($group === 'bano' ? 'Social' : 'Secundaria');
  }

  /** Cantidad de pisos válida o 1. */
  public static function floors(mixed $value): int
  {
    $floors = (int) preg_replace('/\D/', '', (string) $value);
    return max(1, min(self::MAX_FLOORS, $floors));
  }

  /** Piso guardado como número; los textos antiguos ("2do", "Primer") se leen como su número o como piso 1. */
  public static function floorNumber(mixed $value): int
  {
    return self::floors($value);
  }

  /** El servicio se cobra doble si es amoblado o tiene 2 pisos o más. Con ambas condiciones se duplica una sola vez. */
  public static function doubleCharge(string $category, mixed $floors): bool
  {
    return strcasecmp(trim($category), self::FURNISHED) === 0 || self::floors($floors) >= 2;
  }

  /**
   * ¿El elemento es eléctrico? Compara singular y plural sin tildes ("Toma" = "Tomas", "Lámpara" = "Lamparas").
   *
   * @param list<string> $catalog glosario de elementos eléctricos
   */
  public static function isElectrical(string $description, array $catalog): bool
  {
    $value = self::stem($description);
    if ($value === '') return false;
    foreach (array_merge($catalog, self::EXTRA_ELECTRICAL) as $item) {
      $stem = self::stem($item);
      if ($stem !== '' && ($value === $stem || str_starts_with($value, $stem . ' '))) {
        return true;
      }
    }
    return false;
  }

  /** Filas guardadas → filas neutrales {d,c,m,e,o,p,src}. @return list<array{d:string,c:string,m:string,e:string,o:string,p:string,src:string}> */
  public static function rowsFromStored(mixed $raw, string $suffix): array
  {
    $out = [];
    foreach (\SCM\Modules\DeliveryReview\DeliveryReviewForm::rows($raw) as $row) {
      $item = [
        'd' => self::rowText($row['descripcion_' . $suffix] ?? ''),
        'c' => self::rowText($row['cantidad_' . $suffix] ?? ''),
        'm' => self::rowText($row['tipo_de_material_' . $suffix] ?? ''),
        'e' => self::rowText($row['estado_' . $suffix] ?? ''),
        'o' => self::rowText($row['observaciones_' . $suffix] ?? ''),
      ];
      if (implode('', $item) === '') {
        continue;
      }
      $out[] = $item + ['p' => self::rowText($row['piso_' . $suffix] ?? ''), 'src' => self::rowText($row[self::ELECTRICAL_SOURCE] ?? '')];
    }
    return $out;
  }

  /** Filas neutrales → repeater de JetEngine, con las mismas claves de JetForm. @param list<array<string,string>> $rows */
  public static function rowsToStored(array $rows, string $suffix, bool $withMaterial = true): string
  {
    $stored = [];
    foreach ($rows as $row) {
      $item = [
        'descripcion_' . $suffix => (string) ($row['d'] ?? ''),
        'cantidad_' . $suffix => (string) ($row['c'] ?? ''),
      ];
      if ($withMaterial) {
        $item['tipo_de_material_' . $suffix] = (string) ($row['m'] ?? '');
      }
      $item['estado_' . $suffix] = (string) ($row['e'] ?? '');
      $item['observaciones_' . $suffix] = (string) ($row['o'] ?? '');
      if (($row['p'] ?? '') !== '') {
        $item['piso_' . $suffix] = (string) $row['p'];
      }
      if (($row['src'] ?? '') !== '') {
        $item[self::ELECTRICAL_SOURCE] = (string) $row['src'];
      }
      $stored[] = $item;
    }
    return \SCM\Modules\DeliveryReview\DeliveryReviewForm::storeRows($stored);
  }

  private static function rowText(mixed $value): string
  {
    if (is_array($value)) {
      return '';
    }
    return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  private static function stem(string $value): string
  {
    $words = preg_split('/\s+/', self::normalize($value)) ?: [];
    $words = array_map(static function (string $word): string {
      if (strlen($word) > 4 && str_ends_with($word, 'es') && preg_match('/[rnld]es$/', $word) === 1) return substr($word, 0, -2);
      if (strlen($word) > 3 && str_ends_with($word, 's')) return substr($word, 0, -1);
      return $word;
    }, array_filter($words));
    return implode(' ', $words);
  }

  private static function normalize(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
  }
}
