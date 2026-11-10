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
  public const FURNISHED = 'Amoblado';
  public const NEW_TYPE = 'Nuevo';

  private const ROOM_WORDS = ['principal', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez'];

  /**
   * Secciones en el orden del formulario. `suffix` es el sufijo de las claves de cada fila (descripcion_<suffix>…).
   *
   * @return array<string,array{label:string,group:string,glossary:int,suffix:string,furniture:string,furniture_suffix:string,floor:string,details:string,index:int}>
   */
  public static function sections(): array
  {
    $section = static fn(string $label, string $group, int $glossary, string $suffix, string $furniture, string $furnitureSuffix, string $floor = '', string $details = '', int $index = 0): array => [
      'label' => $label, 'group' => $group, 'glossary' => $glossary, 'suffix' => $suffix, 'furniture' => $furniture,
      'furniture_suffix' => $furnitureSuffix, 'floor' => $floor, 'details' => $details, 'index' => $index,
    ];
    $sections = [
      'elementos_principales' => $section('Elementos principales', 'general', 667, 'principales', 'elementos_principales_muebles', 'principales_muebles'),
      'sala' => $section('Sala, comedor y hall', 'general', 669, 'sala', 'sala_muebles', 'sala_muebles'),
      'cocina_o_cafeteria' => $section('Cocina o cafetería', 'general', 675, 'cocina_o_cafeteria', 'cocina_o_cafeteria_muebles', 'cocina_o_cafeteria_muebles'),
      'patio' => $section('Patio y/o zona de labores', 'general', 679, 'patio', 'patio_muebles', 'patio_muebles'),
      'bano_emergencia' => $section('Baño de emergencia', 'general', 674, 'bano_emergencia', 'bano_emergencia_muebles', 'bano_emergencia_muebles', 'piso_bano_emergencia', 'detalles_bano_emergencia'),
    ];
    $names = self::ROOM_WORDS;
    foreach ($names as $position => $word) {
      $key = 'habitacion_' . $word;
      $sections[$key] = $section('Habitación ' . $word, 'habitacion', 676, $key, $key . '_muebles', $key . '_muebles', 'piso_' . $key, 'detalles_' . $key, $position + 1);
    }
    foreach ($names as $position => $word) {
      $key = 'bano_' . $word;
      $sections[$key] = $section('Baño ' . $word, 'bano', 677, $key, $key . '_muebles', $key . '_muebles', 'piso_' . $key, 'detalles_' . $key, $position + 1);
    }
    $sections['garaje'] = $section('Garaje', 'general', 678, 'garaje', 'garaje_muebles', 'garaje_muebles');
    $sections['elementos_electricos'] = $section('Elementos eléctricos', 'general', 668, 'electricos', 'elementos_electricos_muebles', 'electricos_muebles');
    $sections['otros'] = $section('Otros', 'general', 0, 'otros', 'otros_muebles', 'otros_muebles');
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
   * Qué secciones muestra el formulario y cómo se llaman según el tipo de inmueble. Las columnas no cambian: un local
   * guarda sus oficinas en habitacion_* y las muestra como "Área u oficina".
   *
   * @return array{key:string,rooms_label:string,baths_label:string,labels:array<string,string>,hidden:list<string>,max_rooms:int,max_baths:int}
   */
  public static function profile(string $propertyType): array
  {
    $type = self::normalizeText($propertyType);
    $commercial = in_array($type, ['local', 'oficina', 'hotel', 'consultorio'], true);
    $base = ['key' => 'residencial', 'rooms_label' => 'Habitaciones', 'baths_label' => 'Baños', 'labels' => [], 'hidden' => [], 'max_rooms' => self::MAX_ROOMS, 'max_baths' => self::MAX_ROOMS];
    if ($type === 'apartaestudio') {
      return ['key' => 'apartaestudio', 'max_rooms' => 1, 'max_baths' => 2, 'hidden' => ['bano_emergencia', 'garaje']] + $base;
    }
    if ($type === 'lote' || $type === 'casa lote') {
      if ($type === 'casa lote') {
        return ['key' => 'casa_lote'] + $base;
      }
      return [
        'key' => 'lote', 'rooms_label' => 'Construcciones', 'baths_label' => 'Baños', 'max_rooms' => 2, 'max_baths' => 1,
        'labels' => ['elementos_principales' => 'Cerramiento y accesos', 'otros' => 'Construcciones y otros elementos'],
        'hidden' => ['sala', 'cocina_o_cafeteria', 'patio', 'bano_emergencia', 'garaje'],
      ] + $base;
    }
    if ($commercial || $type === 'bodega') {
      $labels = [
        'sala' => $type === 'bodega' ? 'Área de bodega' : 'Área principal o recepción',
        'cocina_o_cafeteria' => 'Cafetería o cocineta',
        'patio' => 'Zona de servicio',
      ];
      foreach (self::sections() as $key => $section) {
        if ($section['group'] === 'habitacion') {
          $labels[$key] = 'Área u oficina ' . $section['index'];
        } elseif ($section['group'] === 'bano') {
          $labels[$key] = 'Baño ' . $section['index'];
        }
      }
      return ['key' => $type === 'bodega' ? 'bodega' : 'comercial', 'rooms_label' => 'Áreas u oficinas', 'labels' => $labels, 'hidden' => ['bano_emergencia']] + $base;
    }
    return $base;
  }

  public static function sectionLabel(string $key, array $profile): string
  {
    return (string) ($profile['labels'][$key] ?? self::sections()[$key]['label'] ?? $key);
  }

  /** Cantidad de pisos válida o 1. */
  public static function floors(mixed $value): int
  {
    $floors = (int) preg_replace('/\D/', '', (string) $value);
    return max(1, min(self::MAX_FLOORS, $floors));
  }

  /** El servicio se cobra doble si es amoblado o tiene 2 pisos o más. Con ambas condiciones se duplica una sola vez. */
  public static function doubleCharge(string $category, mixed $floors): bool
  {
    return strcasecmp(trim($category), self::FURNISHED) === 0 || self::floors($floors) >= 2;
  }

  /** Filas guardadas → filas neutrales {d,c,m,e,o}. @return list<array{d:string,c:string,m:string,e:string,o:string}> */
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
      if (implode('', $item) !== '') {
        $out[] = $item;
      }
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

  private static function normalizeText(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
  }
}
