<?php

declare(strict_types=1);

namespace SCM\Modules\DeliveryReview;

use SCM\Core\Database;

/**
 * Campos de la revisión antes de la ocupación (CCT revision_entrega), tomados del formulario JetForm
 * "Añadir revisión de entrega": linderos, registro fotográfico por espacio y servicios públicos.
 * Las opciones salen de los glosarios de JetEngine; los valores de respaldo son los vigentes en el glosario.
 * Sin "acuerdo de pago" ni "resultado de la revisión" (se quitaron del proceso).
 */
final class DeliveryReviewForm
{
  /** @var array<int,list<string>> */
  private const GLOSSARY_FALLBACK = [
    175 => ['Si', 'No'],
    1011 => ['Asignado', 'Compartido', 'Privado', 'Visitante'],
    611 => ['Fachada frontal', 'Fachada posterior', 'Fachada lateral izquierdo', 'Fachada lateral derecho'],
    557 => ['Frontal', 'Lateral izquierdo', 'Lateral derecho', 'Puerta principal', 'Cerraduras puerta principal', 'Rejas', 'Ventanas', 'Vidrios', 'Terraza', 'Jardinera', 'Balcon', 'Plafon'],
    558 => ['Techos', 'Interruptores', 'Lampara', 'Luminarias', 'Rocetas', 'Apliques', 'Paredes', 'Pisos', 'Puertas', 'Plafon', 'Otros elementos no relacionados'],
    559 => ['Paredes', 'Pisos', 'Closet', 'Lampara', 'Ventana', 'Puerta ventana', 'Balcon', 'Luminarias', 'Plafon', 'Otros elementos no relacionados'],
    560 => ['Mueble del baño', 'Jaboneras', 'Toalleras', 'Otros accesorios de baños', 'Porcelana sanitaria', 'Interiores del sanitario', 'Ducha', 'Registro de ducha', 'Espejos', 'Division de baños', 'Cerradura puerta', 'Toma corriente', 'Lampara', 'Pisos', 'Extractores', 'Cepillera', 'Interruptor gfci', 'Repisas', 'Plafon', 'Otros elementos no relacionados'],
    561 => ['Paredes', 'Pisos', 'Closet', 'Lampara', 'Luminarias', 'Plafon', 'Otros elementos no relacionados'],
    562 => ['Mueble de baño', 'Jaboneras', 'Otros accesorios de baños', 'Porcelana sanitaria', 'Interiores del sanitario', 'Ducha', 'Registro de ducha', 'Espejos', 'Division de baños', 'Cerradura puerta', 'Toma corriente', 'Lampara', 'Luminarias', 'Paredes', 'Pisos', 'Extractores', 'Interruptor gfci', 'Repisas', 'Plafon', 'Otros elementos no relacionados'],
    563 => ['Puertas de acceso', 'Cerraduras', 'Pisos', 'Meson', 'Extractores', 'Estufa', 'Campana', 'Muebles', 'Interruptor gfci', 'Citofono', 'Plafon', 'Otros elementos no relacionados'],
    564 => ['Paredes', 'Pisos', 'Circuitos electricos', 'Muebles', 'Puerta', 'Puerta de acceso', 'Cerraduras', 'Rejas', 'Plafon', 'Otros elementos no relacionados'],
    566 => ['Paredes', 'Pisos', 'Plantas', 'Arboles frutales', 'Salidas de lavadoras', 'Salidas de calentadores', 'Lavadero', 'Griferias lavadero', 'Lavatrapero', 'Citofonos', 'Ventanas', 'Lampara', 'Luminarias', 'Ojo de buey', 'Plafon', 'Otros elementos no relacionados'],
    567 => ['Igual', 'Diferente'],
    568 => ['Detenido por comprobación de no fuga', 'Detenido por deterioro', 'Movimiento por comprobación de no fuga', 'Movimiento por deterioro'],
    569 => ['Coincide', 'No coincide'],
    570 => ['Sin fugas', 'Con fugas'],
    577 => ['Tiene telefonia', 'Tiene internet', 'Tiene television'],
    1003 => ['Agua', 'Gas', 'Energia'],
  ];

  /** Etiquetas visibles del glosario 577 (el valor guardado no lleva signos). */
  public const EXTRA_SERVICE_LABELS = [
    'Tiene telefonia' => 'Telefonía',
    'Tiene internet' => 'Internet',
    'Tiene television' => 'Televisión',
  ];

  /** @var array<int,list<string>> */
  private static array $cache = [];

  /**
   * Linderos: conjunto, torre e inmueble llevan una sola fila; parqueaderos y depósitos una por celda.
   *
   * @return array<string,array{label:string,suffix:string,sides:list<string>,contract:string,multiple:bool,when:string,required:bool,hint:string}>
   */
  public static function boundaries(): array
  {
    $four = ['norte', 'sur', 'oriente', 'occidente'];
    $six = ['norte', 'sur', 'oriente', 'occidente', 'cenit', 'nadir'];
    return [
      'conjunto' => ['label' => 'Conjunto', 'suffix' => 'conjunto', 'sides' => $four, 'contract' => 'conjunto_lin', 'multiple' => false, 'when' => '', 'required' => false, 'hint' => 'Solo si el inmueble está en un conjunto.'],
      'torre' => ['label' => 'Torre', 'suffix' => 'torre', 'sides' => $four, 'contract' => 'torre_lin', 'multiple' => false, 'when' => '', 'required' => false, 'hint' => 'Solo si el conjunto tiene torres.'],
      'inmueble_rep' => ['label' => 'Inmueble', 'suffix' => 'inmueble', 'sides' => $six, 'contract' => 'inmueble_lin', 'multiple' => false, 'when' => '', 'required' => true, 'hint' => 'Linderos propios del inmueble.'],
      'parqueadero' => ['label' => 'Parqueaderos', 'suffix' => 'parqueadero', 'sides' => $six, 'contract' => 'parqueadero_lin', 'multiple' => true, 'when' => 'tiene_parqueadero', 'required' => false, 'hint' => 'Una fila por celda de parqueo.'],
      'deposito' => ['label' => 'Depósitos', 'suffix' => 'deposito', 'sides' => $six, 'contract' => 'deposito_lin', 'multiple' => true, 'when' => 'tiene_deposito', 'required' => false, 'hint' => 'Una fila por depósito.'],
    ];
  }

  /**
   * Espacios del registro fotográfico. "count" liga el espacio a la cantidad de habitaciones o baños del paso 1.
   *
   * @return array<string,array{label:string,item:string,glossary:int,contains:string,observation:string,none:string,type:string,count:string,icon:string}>
   */
  public static function spaces(): array
  {
    $space = static fn(string $label, string $item, int $glossary, string $contains, string $observation, string $none, string $icon, string $type = '', string $count = ''): array
      => compact('label', 'item', 'glossary', 'contains', 'observation', 'none', 'type', 'count', 'icon');
    return [
      'pano_conjunto' => $space('Conjunto', 'Conjunto', 611, 'contiene_conjunto', 'observacion_conjunto', 'ninguna_conjunto', 'apartment'),
      'pano_torre' => $space('Torre', 'Torre', 611, 'contiene_torre', 'observacion_torre', 'ninguna_torre', 'domain'),
      'fachada' => $space('Fachada', 'Fachada', 557, 'contiene_fachada', 'observacion_fachada', 'ninguna_fachada', 'storefront'),
      'sala_comedor_hall' => $space('Sala - Comedor - Hall', 'Sala - comedor', 558, 'contiene_sala', 'observacion_sala', 'ninguna_sala', 'weekend'),
      'habitacion' => $space('Habitaciones', 'Habitación', 559, 'contiene_habitacion', 'observacion_habitacion', 'ninguna_hab', 'bed', 'tipo_habitacion', 'habitaciones'),
      'bano' => $space('Baños', 'Baño', 560, 'contiene_bano', 'observacion_bano', 'ninguna_ba', 'bathtub', 'tipo_ba', 'banos'),
      'cocina' => $space('Cocina', 'Cocina', 563, 'contiene_cocina', 'observacion_cocina', 'ninguna_cocina', 'skillet'),
      'area_labores' => $space('Área de labores', 'Área de labores', 566, 'contiene_area_labores', 'observacion_area_labores', 'ninguna_lab', 'local_laundry_service'),
      'hab_ser' => $space('Habitación de servicio', 'Habitación de servicio', 561, 'contiene_hab_servicio', 'observacion_hab_servicio', 'ninguna_haser', 'single_bed'),
      'bano_ser' => $space('Baño de servicio', 'Baño de servicio', 562, 'contiene_bano_servicio', 'observacion_bano_servicio', 'ninguna_baser', 'shower'),
      'garaje' => $space('Garaje', 'Garaje', 564, 'contiene_garaje', 'observacion_garaje', 'ninguna_garaje', 'garage'),
      'patio' => $space('Patio', 'Patio', 566, 'contiene_patio', 'observacion_patio', 'ninguna_patio', 'yard'),
    ];
  }

  /** Espacios que se agregan con una fila al crear (los demás se agregan si el inmueble los tiene). */
  public const DEFAULT_SPACES = ['fachada', 'sala_comedor_hall', 'cocina', 'area_labores'];

  public const MAX_FLOORS = 10;
  public const SERVICE_TYPE = 'De servicio';

  /** Habitación y baño de servicio se eligen como tipo dentro de habitaciones y baños; se guardan en sus columnas. */
  public const MERGED = ['habitacion' => 'hab_ser', 'bano' => 'bano_ser'];

  /** Piso de cada habitación o baño dentro de la fila del repeater. */
  public const FLOOR_KEYS = ['habitacion' => 'piso_habitacion', 'bano' => 'piso_bano', 'hab_ser' => 'piso_hab_servicio', 'bano_ser' => 'piso_bano_servicio'];

  /** @return list<string> */
  public static function typeOptions(string $space): array
  {
    return $space === 'bano'
      ? ['Principal', 'Social', 'Privado', 'Auxiliar', self::SERVICE_TYPE]
      : ['Principal', 'Secundaria', 'Estudio', self::SERVICE_TYPE];
  }

  /** Tipo escrito a mano en el formulario de WordPress ("#1 o Principal", "#2 o Social o Compartido") → opción de la lista. */
  public static function normalizeType(string $space, string $value): string
  {
    $value = trim($value);
    if ($value === '' || in_array($value, self::typeOptions($space), true)) {
      return $value;
    }
    $key = mb_strtolower($value, 'UTF-8');
    $map = ['servicio' => self::SERVICE_TYPE, 'princip' => 'Principal'];
    $map += $space === 'bano'
      ? ['social' => 'Social', 'compartid' => 'Social', 'privad' => 'Privado', 'auxiliar' => 'Auxiliar']
      : ['estudio' => 'Estudio', 'secundari' => 'Secundaria', 'auxiliar' => 'Secundaria'];
    foreach ($map as $needle => $option) {
      if (str_contains($key, $needle)) {
        return $option;
      }
    }
    // Solo un número ("#1", "#3"): la 1 es la principal y las demás secundarias o sociales.
    if (preg_match('/^#?\s*(\d+)$/', $key, $match) === 1) {
      return (int) $match[1] === 1 ? 'Principal' : self::typeOptions($space)[1];
    }
    return $value;
  }

  /**
   * Habitaciones y baños (incluidos los de servicio) agrupados por piso, con tipo, contenido y observación.
   *
   * @param array<string,mixed> $review
   * @return array<int,array<string,list<array{tipo:string,contiene:list<string>,observacion:string}>>>
   */
  public static function roomsByFloor(array $review): array
  {
    $spaces = self::spaces();
    $out = [];
    foreach (self::MERGED as $base => $service) {
      foreach ([$base, $service] as $key) {
        foreach (self::rows($review[$key] ?? '') as $row) {
          $floor = max(1, min(self::MAX_FLOORS, (int) ($row[self::FLOOR_KEYS[$key]] ?? 1)));
          $type = $key === $service ? self::SERVICE_TYPE : self::normalizeType($base, (string) ($row[$spaces[$base]['type']] ?? ''));
          $out[$floor][$base][] = [
            'tipo' => $type,
            'contiene' => self::values($row[$spaces[$key]['contains']] ?? []),
            'observacion' => trim((string) ($row[$spaces[$key]['observation']] ?? '')),
          ];
        }
      }
    }
    ksort($out);
    return $out;
  }

  /**
   * Servicios públicos. Cada ítem tiene sus campos y una observación (columna ob_*), como el formulario original.
   *
   * @return array<string,array{label:string,option:string,icon:string,items:list<array{label:string,observation:string,fields:list<array{name:string,type:string,label:string,glossary:int,required:bool,contract:string}>}>}>
   */
  public static function services(): array
  {
    $f = static fn(string $name, string $type, string $label, int $glossary = 0, bool $required = false, string $contract = ''): array
      => compact('name', 'type', 'label', 'glossary', 'required', 'contract');
    $item = static fn(string $label, string $observation, array $fields): array => compact('label', 'observation', 'fields');
    return [
      'agua' => ['label' => 'Agua', 'option' => 'Agua', 'icon' => 'water_drop', 'items' => [
        $item('Póliza', 'ob_num_pol_agua', [$f('num_pol_ag', 'text', 'Número de póliza', 0, true, 'agua')]),
        $item('Medidor', 'ob_num_med_agua', [$f('num_med_ag', 'text', 'Número de medidor', 0, true, 'medidor_agua'), $f('num_med_igual_ag', 'select', '¿Igual al de la factura?', 567, true)]),
        $item('Estado del medidor', 'ob_est_med_agua', [$f('est_med_ag', 'select', '¿Detenido o con movimiento?', 568, true)]),
        $item('Dirección en la factura', 'ob_dir_agua', [$f('dir_ag', 'select', '¿Coincide con el inmueble?', 569, true)]),
        $item('Última factura', 'ob_fac_agua', [$f('fac_ag', 'text', '# de factura'), $f('valor_fac_ag', 'money', 'Valor'), $f('fc_pago_ag', 'date', 'Fecha de pago')]),
        $item('Suspensión', 'ob_fc_sus_agua', [$f('fc_sus_ag', 'date', 'Fecha de suspensión')]),
        $item('Puntos hidráulicos', 'ob_pt_hidra_agua', [$f('pt_hidra_agua', 'select', 'Estado', 570), $f('ind_pt_agua', 'text', 'Indicar')]),
        $item('Lectura', 'ob_lec_agua', [$f('lec_ag', 'text', 'Lectura del medidor', 0, true)]),
      ]],
      'gas' => ['label' => 'Gas', 'option' => 'Gas', 'icon' => 'local_fire_department', 'items' => [
        $item('Código de servicio', 'ob_num_ser_gas', [$f('num_ser_gas', 'text', 'Código de servicio', 0, true, 'gas')]),
        $item('Medidor', 'ob_num_med_gas', [$f('num_med_gas', 'text', 'Número de medidor', 0, true, 'medidor_gas'), $f('num_med_igual_gas', 'select', '¿Igual al de la factura?', 567, true)]),
        $item('Estado del medidor', 'ob_est_med_gas', [$f('est_med_gas', 'select', '¿Detenido o en movimiento?', 568, true)]),
        $item('Dirección en la factura', 'ob_dir_gas', [$f('dir_gas', 'select', '¿Coincide con el inmueble?', 569, true)]),
        $item('Última factura', 'ob_fac_gas', [$f('fac_gas', 'text', '# de factura'), $f('valor_fac_gas', 'money', 'Valor'), $f('fc_pago_gas', 'date', 'Fecha de pago')]),
        $item('Suspensión', 'ob_fc_sus_gas', [$f('fc_sus_gas', 'date', 'Fecha de suspensión')]),
        $item('Puntos de gas', 'ob_pt_gas', [$f('pt_gas', 'select', 'Estado', 570), $f('ind_pt_gas', 'text', 'Indicar')]),
        $item('Lectura', 'ob_lec_gas', [$f('lec_gas', 'text', 'Lectura del medidor', 0, true)]),
        $item('Uso', 'ob_uso_gas', [$f('uso_gas', 'select', '¿Corresponde al uso del inmueble?', 175)]),
        $item('Estado de deuda', 'ob_est_dd_gas', [$f('est_dd_gas', 'text', 'Estado de deuda')]),
      ]],
      'luz' => ['label' => 'Energía', 'option' => 'Energia', 'icon' => 'bolt', 'items' => [
        $item('NIC', 'ob_num_nic_luz', [$f('num_nic_luz', 'text', 'Número de NIC', 0, true, 'luz')]),
        $item('Medidor', 'ob_num_med_luz', [$f('num_med_luz', 'text', 'Número de medidor', 0, true, 'medidor_luz'), $f('num_med_igual_luz', 'select', '¿Igual al de la factura?', 567, true)]),
        $item('Estado del medidor', 'ob_est_med_luz', [$f('est_med_luz', 'select', '¿Detenido o en movimiento?', 568, true)]),
        $item('Dirección en la factura', 'ob_dir_luz', [$f('dir_luz', 'select', '¿Coincide con el inmueble?', 569, true)]),
        $item('Última factura', 'ob_fac_luz', [$f('fac_luz', 'text', '# de factura'), $f('valor_fac_luz', 'money', 'Valor'), $f('fc_pago_luz', 'date', 'Fecha de pago')]),
        $item('Suspensión', 'ob_fc_sus_luz', [$f('fc_sus_luz', 'date', 'Fecha de suspensión')]),
        $item('Puntos eléctricos', 'ob_pt_luz', [$f('pt_luz', 'select', '¿En buen estado?', 175), $f('ind_pt_luz', 'text', 'Indicar')]),
        $item('Lectura', 'ob_lec_luz', [$f('lec_luz', 'text', 'Lectura del medidor', 0, true)]),
        $item('Uso', 'ob_uso_luz', [$f('uso_luz', 'select', '¿Corresponde al uso del inmueble?', 175)]),
        $item('Estado de deuda', 'ob_est_dd_luz', [$f('est_dd_luz', 'text', 'Estado de deuda')]),
      ]],
    ];
  }

  /** Contrato → columna de lectura inicial que se actualiza con la lectura tomada en la revisión. */
  public const CONTRACT_READINGS = ['lec_ag' => 'lectura_inicial_agua', 'lec_gas' => 'lectura_inicial_gas', 'lec_luz' => 'lectura_inicial_luz'];

  /** Opciones de un glosario de JetEngine (jet_post_types, status = glossary). @return list<string> */
  public static function glossary(?Database $db, int $id): array
  {
    if (isset(self::$cache[$id])) {
      return self::$cache[$id];
    }
    $out = [];
    if ($db !== null) {
      try {
        $raw = $db->getVar("SELECT `meta_fields` FROM `{$db->table('jet_post_types')}` WHERE `id` = ? AND `status` = 'glossary' LIMIT 1", [$id]);
        $items = @unserialize((string) ($raw ?? ''), ['allowed_classes' => false]);
        foreach (is_array($items) ? $items : [] as $item) {
          $value = trim((string) ($item['value'] ?? ''));
          if ($value !== '') {
            $out[$value] = $value;
          }
        }
      } catch (\Throwable) {
        $out = [];
      }
    }
    return self::$cache[$id] = $out !== [] ? array_values($out) : (self::GLOSSARY_FALLBACK[$id] ?? []);
  }

  /**
   * Repeater guardado por JetForm/JetEngine ("item-0" => [...]) como lista de filas.
   *
   * @return list<array<string,mixed>>
   */
  public static function rows($raw): array
  {
    if (is_string($raw)) {
      $raw = trim($raw);
      if ($raw === '') {
        return [];
      }
      $decoded = @unserialize($raw, ['allowed_classes' => false]);
      if ($decoded === false && $raw !== 'b:0;') {
        $decoded = json_decode($raw, true);
      }
      $raw = $decoded;
    }
    if (!is_array($raw)) {
      return [];
    }
    $rows = [];
    foreach ($raw as $row) {
      if (is_array($row)) {
        $rows[] = $row;
      }
    }
    return $rows;
  }

  /** Lista guardada (checkbox de JetForm): admite lista simple o el formato valor => "true". @return list<string> */
  public static function values($raw): array
  {
    if (is_string($raw)) {
      $text = trim($raw);
      if ($text === '') {
        return [];
      }
      $decoded = @unserialize($text, ['allowed_classes' => false]);
      $raw = is_array($decoded) ? $decoded : (json_decode($text, true) ?? array_map('trim', explode(',', $text)));
    }
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $key => $value) {
      if (is_string($key) && in_array($value, ['true', true, 1, '1'], true)) {
        $out[] = trim($key);
      } elseif (is_scalar($value) && !in_array($value, ['false', false], true)) {
        $out[] = trim((string) $value);
      }
    }
    return array_values(array_unique(array_filter($out, static fn(string $value): bool => $value !== '')));
  }

  /** Serializa filas como un repeater de JetEngine (claves item-N). @param list<array<string,mixed>> $rows */
  public static function storeRows(array $rows): string
  {
    if ($rows === []) {
      return '';
    }
    $out = [];
    foreach (array_values($rows) as $index => $row) {
      $out['item-' . $index] = $row;
    }
    return serialize($out);
  }

  /** Lo que el formulario mostraba como "Ninguna" cuenta como sin observaciones. */
  public static function isNone(string $value): bool
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return $value === '' || $value === 'ninguna' || $value === 'ninguno' || $value === 'n/a';
  }
}
