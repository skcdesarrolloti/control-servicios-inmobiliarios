<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Core\Database;
use SCM\Modules\LeaseContracts\ContractCoOwners;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;

/**
 * Reemplaza los formularios JetForm "Añadir / Editar hoja de cierre de negocio".
 *
 * Al crear hace lo mismo que las 12 acciones del formulario (hoja, mandato, historial del inmueble, copropiedad,
 * ticket y su historial, inmueble CCT y post, arrendatario y correos), todo en una transacción. Además:
 * - Guarda los copropietarios del mandato con su porcentaje y registra en Propietarios a quien no tenga ficha.
 * - Todo dato de la hoja que también está en el mandato se escribe en el mandato (dirección, servicios, copropiedad,
 *   parqueadero, depósito, días de gracia, propietario principal y porcentajes), para que no queden distintos.
 * - Los correos a funcionarios salen del evento interno "Hoja de cierre creada", no de direcciones fijas.
 */
final class ClosingSheetService
{
  use ClosingSheetRecords;

  public const INTERNAL_ACTION = 'hoja_cierre_creada';
  public const STATUS_NEW = 'En verificacion de servicios';

  /** Glosarios JetEngine del formulario original. */
  private const GLOSSARIES = [
    'tipo_inmueble' => 783, 'gestion_inmueble' => 784, 'destinacion_inmueble' => 785, 'si_no' => 175,
    'servicios_publicos' => 1003, 'tipo_parqueadero' => 1011, 'tipo_mascota' => 1016, 'estado_checklist' => 1154,
    'acuerdos' => 853, 'ocupacion' => 990, 'parentesco' => 991, 'tipo_vehiculo' => 992,
  ];

  /** Acuerdo marcado => columna de su texto (los no marcados se guardan vacíos). */
  private const AGREEMENTS = [
    'Propietario' => 'acuerdos_precontractuales_pro', 'Inmobiliaria' => 'acuerdos_precontractuales_inm',
    'Arrendatario' => 'acuerdos_precontractuales_arre', 'Externo' => 'acuerdos_precontractuales_ext',
    'Copropiedad' => 'acuerdos_precontractuales_co',
  ];

  /** Columna de la hoja => columna del mandato. */
  public const MANDATE_SYNC = [
    'direccion' => 'direccion_fisica', 'servicios_publicos' => 'servicios_publicos', 'luz' => 'energia', 'agua' => 'agua', 'gas' => 'gas',
    'propiedad_horizontal' => 'propiedad_horizontal', 'copropiedad' => 'copropiedad', 'nit_copropiedad' => 'nit_co',
    'administrador' => 'administrador_co', 'correo_copropiedad' => 'correo_co', 'celular_copropiedad' => 'contacto_co',
    'tipo_cuenta_copropiedad' => 'tipo_cuenta_co', 'cuenta_copropiedad' => 'numero_cuenta_co', 'banco_copropiedad' => 'banco_cuenta_co',
    'tiene_parqueadero' => 'tiene_parqueadero', 'tipo_parqueadero' => 'tipo_parqueadero', 'parqueaderos' => 'parqueadero',
    'numero_parqueadero' => 'numero_parqueadero', 'tiene_deposito' => 'tiene_deposito', 'depositos' => 'depositos',
    'dias_gracia' => 'dias_gracia', 'propietario' => 'nombre_1', 'documento_propietario' => 'documento_1',
    'correo_propietario' => 'correo_1', 'celular_propietario' => 'celular_1', 'tipo_persona_propietario' => 'tipo_1',
    'tipo_documento_propietario' => 'tipo_documento_1', 'representante_propietario' => 'empresa_1', 'documento_representante_propietario' => 'nit_1',
  ];

  /**
   * Condiciones del negocio: columna de la hoja => columna del mandato. Se editan en la hoja y pasan al mandato en su
   * formato ("1.150.000", "12 Meses"; administración sin valor = "0"; IVA que no aplica = vacío).
   */
  public const MANDATE_TERMS = [
    'valor_canon' => 'precio', 'valor_administracion' => 'administracion', 'incluye_iva' => 'incluye_iva', 'iva_total_precio' => 'iva_total_precio',
    'meses_contrato' => 'duracion', 'tasa_administracion' => 'tasa_comision', 'porcentaje_incremento' => 'incremento_pactado',
  ];

  /** Columna de la hoja (sin _propietario / _arrendatario) => columna de la ficha. */
  private const PERSON_PROFILE = [
    'tipo_persona' => 'tipo_persona', 'tipo_documento' => 'tipo_documento', 'representante' => 'nombre_juridico', 'documento_representante' => 'documento_juridico',
  ];

  private const SCHEDULES = [
    'horario_mudanza_inicio_1', 'horario_mudanza_final_1', 'horario_mudanza_inicio_2', 'horario_mudanza_final_2',
    'horario_mudanza_inicio_sabado', 'horario_mudanza_final_sabado', 'horario_zonas_inicio', 'horario_zonas_final',
    'horario_gym_inicio', 'horario_gym_final', 'horario_piscina_inicio', 'horario_piscina_final',
    'horario_canchas_inicio', 'horario_canchas_final',
  ];

  /** Columna de la hoja => columna del CCT inmuebles. */
  private const PROPERTY_SYNC = [
    'servicios_publicos' => 'servicios_publicos', 'luz' => 'luz', 'agua' => 'agua', 'gas' => 'gas', 'zonas_sociales' => 'zonas_sociales',
    'tiene_parqueadero' => 'parqueadero', 'tipo_parqueadero' => 'tipo_parqueadero', 'parqueaderos' => 'parqueaderos',
    'numero_parqueadero' => 'numero_parqueadero', 'tiene_deposito' => 'deposito', 'depositos' => 'depositos',
  ];

  /** Columna de la hoja => meta del post "inmuebles" en WordPress. */
  private const POST_META = [
    'servicios_publicos' => 'servicios-publicos', 'luz' => 'luz', 'agua' => 'agua', 'gas' => 'gas',
    'tiene_parqueadero' => 'tiene-parqueadero', 'tipo_parqueadero' => 'tipo-parqueadero', 'parqueaderos' => 'parqueaderos',
    'numero_parqueadero' => 'parqueadero', 'tiene_deposito' => 'tiene-deposito', 'depositos' => 'depositos',
    'propiedad_horizontal' => 'pertenece-regimen', 'zonas_sociales' => 'zonas-sociales', 'copropiedad' => 'copropiedad',
    'nit_copropiedad' => 'nit-cop', 'administrador' => 'administrador-cop', 'correo_copropiedad' => 'correo-cop',
    'celular_copropiedad' => 'contacto-cop',
  ];

  /** Columna de la hoja => columna del CCT copropiedades. */
  private const BUILDING_SYNC = [
    'copropiedad' => 'copropiedad', 'nit_copropiedad' => 'nit', 'administrador' => 'administrador', 'correo_copropiedad' => 'correo',
    'celular_copropiedad' => 'contacto', 'tipo_cuenta_copropiedad' => 'tipo_cuenta', 'cuenta_copropiedad' => 'numero_cuenta',
    'banco_copropiedad' => 'banco',
  ];

  /** Columnas de los copropietarios 2 a 6 en la hoja (prefijo + número). Se copian del mandato. */
  public const CO_OWNER_COLUMNS = [
    'id_propietario_', 'propietario_', 'documento_propietario_', 'correo_propietario_', 'celular_propietario_',
    'tipo_persona_propietario_', 'tipo_documento_propietario_', 'representante_propietario_', 'documento_representante_propietario_',
  ];

  /** Ids que llegan en el enlace (los mismos del formulario original). */
  public const CONTEXT_IDS = ['id_contrato', 'id_inmueble', 'id_inmueble_data', 'id_propietario', 'id_arrendatario', 'id_copropiedad', 'id_estudio_aseguradora'];

  private SchemaInspector $schema;
  /** @var array<string,list<string>|array<string,string>>|null */
  private ?array $options = null;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /** @return array<string,list<string>|array<string,string>> opciones de cada lista (club_pph es id => etiqueta) */
  public function options(): array
  {
    if ($this->options !== null) {
      return $this->options;
    }
    $options = [];
    foreach (self::GLOSSARIES as $key => $id) {
      $options[$key] = $this->glossary($id);
    }
    $column = function (string $name, string $col): array {
      $table = $this->db->table($name);
      if (!$this->schema->tableExists($table)) {
        return [];
      }
      $values = array_map('trim', $this->db->getCol("SELECT `{$col}` FROM `{$table}` WHERE COALESCE(`cct_status`, 'publish') = 'publish' ORDER BY `{$col}` ASC"));
      return array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
    };
    $options['bancos'] = $column('jet_cct_bancos', 'banco');
    $options['zonas_sociales'] = $column('jet_cct_zonas_sociales', 'valor');
    $options['club_pph'] = [];
    $club = $this->db->table('jet_cct_club_pph');
    if ($this->schema->tableExists($club)) {
      foreach ($this->db->getResults("SELECT `_ID`, `tarjeta_bienvenida`, `nombre` FROM `{$club}` WHERE `activo` = 'Si' ORDER BY `_ID` DESC") as $row) {
        $options['club_pph'][(string) $row['_ID']] = trim(trim((string) $row['tarjeta_bienvenida']) . ' · ' . trim((string) $row['nombre']), ' ·');
      }
    }
    return $this->options = $options;
  }

  /**
   * Datos para abrir la hoja. Crear: a partir de los ids del enlace. Editar: a partir de la hoja guardada.
   * @param array<string,mixed> $query
   * @return array{mode:string,closing_id:int,ids:array<string,string>,values:array<string,mixed>,owners:list<array<string,mixed>>,mandate:array<string,mixed>,address:string}
   */
  public function context(array $query, int $closingId = 0): array
  {
    $closing = $closingId > 0 ? $this->row('jet_cct_cierres', $closingId) : [];
    if ($closingId > 0 && $closing === []) {
      throw new \DomainException('La hoja de cierre #' . $closingId . ' no existe.');
    }
    $ids = [];
    foreach (self::CONTEXT_IDS as $key) {
      $value = trim((string) ($closing[$key] ?? ''));
      if (!ctype_digit($value) || (int) $value <= 0) {
        $value = trim((string) ($query[$key] ?? ''));
      }
      $ids[$key] = ctype_digit($value) && (int) $value > 0 ? $value : '';
    }
    $mandate = $this->row('jet_cct_contrato_mandato', $ids['id_contrato']);
    if ($mandate === []) {
      throw new \DomainException('No se encontró el contrato de mandato' . ($ids['id_contrato'] !== '' ? ' #' . $ids['id_contrato'] : '') . '. Abre la hoja desde el botón "Añadir cierre" del mandato.');
    }
    // Si el enlace no trae algún id, se toma del mandato (id_inmueble es el post de WordPress del inmueble).
    if ($ids['id_inmueble'] === '') {
      $ids['id_inmueble'] = $this->firstId([$mandate['id_inmueble'] ?? '']);
    }
    $properties = $this->db->table('jet_cct_inmuebles');
    if ($ids['id_inmueble_data'] === '' && $this->schema->columnExists($properties, 'id_contrato_mandato')) {
      $ids['id_inmueble_data'] = $this->firstId([$this->db->getVar("SELECT `_ID` FROM `{$properties}` WHERE `id_contrato_mandato` = ? ORDER BY `_ID` DESC LIMIT 1", [(string) $mandate['_ID']])]);
    }
    if ($ids['id_copropiedad'] === ''&& ctype_digit(trim((string) ($mandate['id_copropiedad'] ?? ''))) && (int) $mandate['id_copropiedad'] > 0) {
      $ids['id_copropiedad'] = trim((string) $mandate['id_copropiedad']);
    }
    if ($ids['id_propietario'] === '') {
      $ids['id_propietario'] = $this->firstId([$mandate['id_propietario_nuevo_1'] ?? '', $mandate['id_propietario'] ?? '']);
    }
    $values = $closing !== [] ? $this->editValues($closing, $ids) : $this->createValues($mandate, $ids);
    return [
      'mode' => $closing !== [] ? 'editar' : 'crear',
      'closing_id' => $closingId,
      'ids' => $ids,
      'values' => $values,
      'owners' => $this->owners($mandate, $ids, $closing),
      'mandate' => $mandate,
      'address' => trim((string) ($values['direccion'] ?? '')) ?: trim((string) ($mandate['direccion_fisica'] ?? $mandate['direccion'] ?? '')),
    ];
  }

  /** @param array<string,mixed> $mandate @param array<string,string> $ids @return array<string,mixed> */
  private function createValues(array $mandate, array $ids): array
  {
    $property = $this->row('jet_cct_inmuebles', $ids['id_inmueble_data']);
    $building = $this->row('jet_cct_copropiedades', $ids['id_copropiedad']);
    $owner = $this->row('jet_cct_propietarios', $ids['id_propietario']);
    $tenant = $this->row('jet_cct_arrendatarios', $ids['id_arrendatario']);
    $study = $this->row('jet_cct_estudios_aseguradoras', $ids['id_estudio_aseguradora']);
    $ticketId = trim((string) ($study['id_ticket'] ?? ''));
    $ticket = $this->row('jet_cct_tickets', $ticketId);
    $p = static fn(string $key): string => trim((string) ($property[$key] ?? ''));
    $m = static fn(string $key): string => trim((string) ($mandate[$key] ?? ''));
    $administration = $m('administracion');
    $values = [
      'id_ticket' => $ticketId,
      'tipo_inmueble' => $this->match('tipo_inmueble', $p('tipo_inmueble')),
      'gestion_inmueble' => $this->match('gestion_inmueble', $p('tipo_negocio')),
      'destinacion_inmueble' => $this->match('destinacion_inmueble', $p('destinacion')),
      'incluye_iva' => $m('incluye_iva'),
      'meses_contrato' => (string) (int) $m('duracion') ?: '',
      'valor_canon' => $m('precio'),
      // Como el formulario: sin administración (vacía o en cero) queda "No aplica"; sin IVA, el IVA queda "No aplica".
      'valor_administracion' => (int) preg_replace('/\D+/', '', $administration) > 0 ? $administration : 'No aplica',
      'iva_total_precio' => strcasecmp($m('incluye_iva'), 'No') === 0 ? 'No aplica' : $m('iva_total_precio'),
      'tasa_administracion' => $m('tasa_comision'),
      'porcentaje_incremento' => $m('incremento_pactado'),
      'dias_gracia' => $m('dias_gracia'),
      'direccion' => $p('direccion_fisica') ?: $m('direccion_fisica'),
      'luz' => $p('luz') ?: $m('energia'), 'agua' => $p('agua') ?: $m('agua'), 'gas' => $p('gas') ?: $m('gas'),
      'propiedad_horizontal' => $p('pertenece_copropiedad') ?: $m('propiedad_horizontal'),
      'copropiedad' => trim((string) ($building['copropiedad'] ?? '')) ?: $m('copropiedad'),
      'nit_copropiedad' => trim((string) ($building['nit'] ?? '')) ?: $m('nit_co'),
      'administrador' => trim((string) ($building['administrador'] ?? '')) ?: $m('administrador_co'),
      'correo_copropiedad' => trim((string) ($building['correo'] ?? '')) ?: $m('correo_co'),
      'celular_copropiedad' => trim((string) ($building['contacto'] ?? '')) ?: $m('contacto_co'),
      'tipo_cuenta_copropiedad' => trim((string) ($building['tipo_cuenta'] ?? '')) ?: $m('tipo_cuenta_co'),
      'cuenta_copropiedad' => trim((string) ($building['numero_cuenta'] ?? '')) ?: $m('numero_cuenta_co'),
      'banco_copropiedad' => trim((string) ($building['banco'] ?? '')) ?: $m('banco_cuenta_co'),
      'zonas_sociales' => $this->list($property['zonas_sociales'] ?? ''),
      'tiene_parqueadero' => $p('parqueadero') ?: $m('tiene_parqueadero'),
      'tipo_parqueadero' => $p('tipo_parqueadero') ?: $m('tipo_parqueadero'),
      'parqueaderos' => $p('parqueaderos') ?: $m('parqueadero'),
      'numero_parqueadero' => $p('numero_parqueadero') ?: $m('numero_parqueadero'),
      'tiene_deposito' => $p('deposito') ?: $m('tiene_deposito'),
      'depositos' => $p('depositos') ?: $m('depositos'),
      'propietario' => trim((string) ($owner['nombre'] ?? '')) ?: $m('nombre_1'),
      'documento_propietario' => trim((string) ($owner['documento'] ?? '')) ?: $m('documento_1'),
      'correo_propietario' => trim((string) ($owner['correo'] ?? '')) ?: $m('correo_1'),
      'celular_propietario' => trim((string) ($owner['celular'] ?? '')) ?: $m('celular_1'),
      'tipo_persona_propietario' => self::personType(trim((string) ($owner['tipo_persona'] ?? '')) ?: $m('tipo_1')),
      'tipo_documento_propietario' => trim((string) ($owner['tipo_documento'] ?? '')) ?: $m('tipo_documento_1'),
      'representante_propietario' => trim((string) ($owner['nombre_juridico'] ?? '')) ?: $m('empresa_1'),
      'documento_representante_propietario' => trim((string) ($owner['documento_juridico'] ?? '')) ?: $m('nit_1'),
      'tipo_persona_arrendatario' => self::personType((string) ($tenant['tipo_persona'] ?? '')),
      'tipo_documento_arrendatario' => trim((string) ($tenant['tipo_documento'] ?? '')),
      'representante_arrendatario' => trim((string) ($tenant['nombre_juridico'] ?? '')),
      'documento_representante_arrendatario' => trim((string) ($tenant['documento_juridico'] ?? '')),
      'arrendatario' => trim((string) ($tenant['nombre'] ?? '')),
      'documento_arrendatario' => trim((string) ($tenant['documento'] ?? '')),
      'correo_arrendatario' => trim((string) ($tenant['correo'] ?? '')),
      'celular_arrendatario' => trim((string) ($tenant['celular'] ?? '')),
      // Datos del estudio de aseguradora y del ticket que el formulario traía con consultas AJAX.
      'numero_solicitud' => trim((string) ($study['num_solicitud'] ?? '')),
      'aseguradora' => trim((string) ($study['aseguradora'] ?? '')),
      'fecha_posible_entrega' => trim((string) ($study['fecha_posible_entrega'] ?? '')),
      'ubicacion_llaves' => trim((string) ($study['ubicacion_llaves'] ?? '')),
      'persona_llaves' => trim((string) ($study['persona_llaves'] ?? '')),
      'contacto_llaves' => trim((string) ($study['contacto_llaves'] ?? '')),
      'id_contrato_arrendamiento' => trim((string) ($study['id_contrato_mandato_arrendamiento'] ?? '')),
      'medio' => trim((string) ($ticket['medio'] ?? '')),
    ];
    foreach (self::SCHEDULES as $column) {
      $values[$column] = $p($column);
    }
    // La lista de servicios suele estar vacía en el inmueble (el formulario solo la guardaba en el post): se toma la
    // del mandato y, si tampoco la tiene, se marcan los servicios que tienen número de cuenta.
    $services = $this->list($property['servicios_publicos'] ?? '') ?: $this->list($mandate['servicios_publicos'] ?? '');
    if ($services === []) {
      foreach (['Energia' => 'luz', 'Agua' => 'agua', 'Gas' => 'gas'] as $service => $column) {
        if ($values[$column] !== '') {
          $services[] = $this->match('servicios_publicos', $service);
        }
      }
    }
    $values['servicios_publicos'] = $services;
    return $values;
  }

  /** @param array<string,mixed> $closing @param array<string,string> $ids @return array<string,mixed> */
  private function editValues(array $closing, array $ids): array
  {
    $values = [];
    foreach ($this->fields() as $field) {
      $raw = $closing[$field['name']] ?? '';
      $values[$field['name']] = in_array($field['type'], ['checkbox', 'repeater'], true) ? $this->list($raw) : trim((string) $raw);
    }
    // Lista de chequeo: estados y observaciones de cada ítem (se separan del HTML guardado en las columnas ob_*).
    $values['__notes'] = [];
    foreach (ClosingSheetForm::CHECKLIST as $notesColumn => [, , $items]) {
      $values[$notesColumn] = (string) ($closing[$notesColumn] ?? '');
      $notes = self::checklistNotesParse(array_column($items, 2), $values[$notesColumn]);
      foreach ($items as $i => [$column]) {
        $values[$column] = trim((string) ($closing[$column] ?? ''));
        $values['__notes'][$column] = $notes[$i];
      }
    }
    // Como el formulario de edición: copropiedad y propietario se leen de su ficha si la hoja no los tiene.
    $building = $this->row('jet_cct_copropiedades', $ids['id_copropiedad']);
    foreach (self::BUILDING_SYNC as $column => $source) {
      if (($values[$column] ?? '') === '' && trim((string) ($building[$source] ?? '')) !== '') {
        $values[$column] = trim((string) $building[$source]);
      }
    }
    $people = [
      'propietario' => $this->row('jet_cct_propietarios', $ids['id_propietario']),
      'arrendatario' => $this->row('jet_cct_arrendatarios', $ids['id_arrendatario']),
    ];
    foreach (['propietario' => 'nombre', 'documento_propietario' => 'documento', 'correo_propietario' => 'correo', 'celular_propietario' => 'celular'] as $column => $source) {
      if (($values[$column] ?? '') === '' && trim((string) ($people['propietario'][$source] ?? '')) !== '') {
        $values[$column] = trim((string) $people['propietario'][$source]);
      }
    }
    // Tipo de persona, documento y representante: de la ficha si la hoja todavía no los tiene (hojas anteriores).
    foreach ($people as $who => $profile) {
      foreach (self::PERSON_PROFILE as $prefix => $source) {
        $column = $prefix . '_' . $who;
        if (($values[$column] ?? '') === '' && trim((string) ($profile[$source] ?? '')) !== '') {
          $values[$column] = trim((string) $profile[$source]);
        }
      }
      $values['tipo_persona_' . $who] = self::personType((string) ($values['tipo_persona_' . $who] ?? ''));
    }
    return $values;
  }

  /**
   * Formato de las condiciones del negocio como las guarda la hoja: pesos con puntos de miles, meses y comisión como
   * número, administración vacía o en cero = "No aplica" y, sin IVA, el IVA = "No aplica". Devuelve los errores.
   * @param array<string,mixed> $values @return list<string>
   */
  public static function normalizeTerms(array &$values): array
  {
    $errors = [];
    $pesos = static function (string $value): ?string {
      $digits = preg_replace('/[$\s.]/', '', trim($value)) ?? '';
      $digits = preg_replace('/,\d{1,2}$/', '', $digits) ?? $digits;
      return preg_match('/^\d{1,12}$/D', $digits) === 1 ? number_format((int) $digits, 0, ',', '.') : null;
    };
    $canon = trim((string) ($values['valor_canon'] ?? ''));
    if ($canon !== '') {
      $formatted = $pesos($canon);
      $formatted !== null && $formatted !== '0' ? $values['valor_canon'] = $formatted : $errors[] = 'Canon: escribe el valor en pesos, por ejemplo 1.150.000.';
    }
    $administration = trim((string) ($values['valor_administracion'] ?? ''));
    if ($administration === '' || strcasecmp($administration, 'No aplica') === 0 || $pesos($administration) === '0') {
      $values['valor_administracion'] = 'No aplica';
    } else {
      $formatted = $pesos($administration);
      $formatted !== null ? $values['valor_administracion'] = $formatted : $errors[] = 'Administración: escribe el valor en pesos o déjala vacía si no aplica.';
    }
    if (strcasecmp(trim((string) ($values['incluye_iva'] ?? '')), 'Si') !== 0) {
      $values['iva_total_precio'] = 'No aplica';
    } else {
      $iva = trim((string) ($values['iva_total_precio'] ?? ''));
      $formatted = $iva === '' ? null : $pesos($iva);
      $formatted !== null && $formatted !== '0' ? $values['iva_total_precio'] = $formatted : $errors[] = 'IVA del canon: si el canon incluye IVA, escribe su valor en pesos.';
    }
    $months = trim((string) ($values['meses_contrato'] ?? ''));
    if ($months !== '') {
      $number = (int) (preg_replace('/\D+/', '', $months) ?: 0);
      $number >= 1 && $number <= 240 ? $values['meses_contrato'] = (string) $number : $errors[] = 'Meses de contrato: escribe un número de meses, por ejemplo 12.';
    }
    $rate = str_replace([' ', '%'], '', trim((string) ($values['tasa_administracion'] ?? '')));
    if ($rate !== '') {
      $rate = str_replace(',', '.', $rate);
      is_numeric($rate) && (float) $rate >= 0 && (float) $rate <= 100 ? $values['tasa_administracion'] = rtrim(rtrim($rate, '0'), '.') === '' ? '0' : (str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate) : $errors[] = 'Porcentaje de comisión: escribe un número entre 0 y 100, por ejemplo 10.';
    }
    return $errors;
  }

  /** La condición como la guarda el mandato. */
  private static function mandateTerm(string $column, string $value, string $current): string
  {
    return match ($column) {
      // Sin administración el mandato tiene "0" o vacío: se respeta el que ya tenga.
      'valor_administracion' => $value === 'No aplica' ? (trim($current) === '' ? '' : '0') : $value,
      'iva_total_precio' => $value === 'No aplica' ? '' : $value,
      'meses_contrato' => $value !== '' ? $value . ' Meses' : '',
      default => $value,
    };
  }

  /** "Jurídica", "Juridico" y similares se guardan como "Juridica", el valor de los formularios actuales. */
  public static function personType(string $value): string
  {
    $lower = mb_strtolower(trim($value), 'UTF-8');
    return match (true) {
      $lower === '' => '',
      str_starts_with($lower, 'jur') => 'Juridica',
      str_starts_with($lower, 'na') => 'Natural',
      default => trim($value),
    };
  }

  /**
   * Propietarios del mandato con su porcentaje (de la hoja si ya lo tiene, si no del mandato).
   * @param array<string,mixed> $mandate @param array<string,string> $ids @param array<string,mixed> $closing
   * @return list<array{index:int,name:string,document:string,email:string,phone:string,type:string,document_type:string,representative:string,representative_document:string,owner_id:string,percent:string}>
   */
  private function owners(array $mandate, array $ids, array $closing): array
  {
    $resolved = ContractCoOwners::resolve(['id_propietario' => $ids['id_propietario']] + $closing, $mandate);
    $out = [];
    foreach ($resolved as $owner) {
      $index = (int) $owner['index'];
      $percent = trim((string) ($closing['porcentaje_' . $index] ?? ''));
      if ($percent === '' && in_array($owner['share_source'], ['mandato', 'unico', 'contrato'], true)) {
        $percent = self::percentText($owner['share'] * 100);
      }
      $type = self::personType((string) ($mandate['tipo_' . $index] ?? ''));
      $company = $type === 'Juridica';
      $out[] = [
        'index' => $index, 'name' => $owner['name'], 'document' => $owner['document'], 'email' => $owner['email'],
        'phone' => trim((string) ($mandate['celular_' . $index] ?? '')), 'type' => $type,
        'document_type' => trim((string) ($mandate['tipo_documento_' . $index] ?? '')),
        // En el mandato, empresa_N / nit_N son el representante legal de una persona jurídica.
        'representative' => $company ? trim((string) ($mandate['empresa_' . $index] ?? '')) : '',
        'representative_document' => $company ? trim((string) ($mandate['nit_' . $index] ?? '')) : '',
        'owner_id' => ctype_digit((string) $owner['owner_id']) ? (string) $owner['owner_id'] : '',
        'percent' => count($resolved) === 1 ? '100' : $percent,
      ];
    }
    return $out;
  }

  /** @return list<array<string,mixed>> todos los campos del paso 1, incluidos los repetidores */
  public function fields(): array
  {
    $fields = [];
    foreach (ClosingSheetForm::SECTIONS as [, $sectionFields]) {
      foreach ($sectionFields as $field) {
        $fields[] = $field;
      }
    }
    return $fields;
  }

  /**
   * Guarda la hoja (crea si $closingId = 0).
   * @param array<string,mixed> $input POST ya limpio
   * @param array{employee_id:string,name:string,email:string,branch:string} $sender
   * @return array{id:int,mode:string,url:string,edit_url:string,synced:list<string>,notified:int}
   */
  public function save(array $input, array $sender, int $closingId = 0): array
  {
    $context = $this->context($input, $closingId);
    $creating = $context['mode'] === 'crear';
    $ids = $context['ids'];
    $values = $this->validate($input, $context, $creating);
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $mandateTable = $this->db->table('jet_cct_contrato_mandato');
      $mandate = $this->db->getRow("SELECT * FROM `{$mandateTable}` WHERE `_ID` = ? LIMIT 1 FOR UPDATE", [(int) $ids['id_contrato']]) ?? $context['mandate'];
      $owners = $this->registerOwners($context['owners'], $values['__percents'], $sender, $nowSql);
      $closingData = $this->closingColumns($values, $owners);

      if ($creating) {
        if ($this->recentDuplicate($ids, $now)) {
          throw new \DomainException('Ya se guardó una hoja de cierre para este mandato y arrendatario hace un momento. Revisa antes de crear otra.');
        }
        foreach (['numero_solicitud', 'aseguradora', 'fecha_posible_entrega', 'ubicacion_llaves', 'persona_llaves', 'contacto_llaves', 'id_contrato_arrendamiento', 'medio'] as $key) {
          $closingData[$key] = (string) ($context['values'][$key] ?? '');
        }
        $closingData += [
          'id_inmueble' => $ids['id_inmueble'], 'id_inmueble_data' => $ids['id_inmueble_data'], 'id_propietario' => $ids['id_propietario'],
          'id_arrendatario' => $ids['id_arrendatario'], 'id_contrato' => $ids['id_contrato'], 'id_copropiedad' => $ids['id_copropiedad'],
          'id_estudio_aseguradora' => $ids['id_estudio_aseguradora'], 'id_empleado' => $sender['employee_id'], 'creador' => $sender['name'],
          'sucursal' => $sender['branch'], 'fecha' => (string) $now, 'estado' => self::STATUS_NEW,
        ];
        $closingId = $this->insert('jet_cct_cierres', $sender, $nowSql, $closingData);
      } else {
        $current = $this->row('jet_cct_cierres', $closingId);
        $this->update('jet_cct_cierres', $closingId, $closingData + ['cct_modified' => $nowSql]);
        // Una hoja no aprobada que se corrige vuelve a quedar por responder.
        $resubmitted = (new ClosingSheetWorkflow($this->db))->resubmitIfRejected($current, $nowSql);
      }

      $synced = $this->syncMandate($mandate, $values, $owners, $creating ? $closingId : 0, $nowSql);
      $this->syncRecords($ids, $values, $creating ? $closingId : 0);

      if ($creating) {
        $this->insert('jet_cct_historial_del_inmueble', $sender, $nowSql, [
          'id_inmueble' => $ids['id_inmueble'], 'id_inmueble_data' => $ids['id_inmueble_data'], 'fecha' => (string) $now,
          'funcionario' => $sender['name'], 'id_empleado' => $sender['employee_id'],
          'tipo_reporte' => 'En cierre', 'observacion' => 'Su inmueble esta en cierre de negociacion.',
        ]);
        $ticketId = (int) $values['id_ticket'];
        if ($ticketId > 0 && $this->row('jet_cct_tickets', $ticketId) !== []) {
          // Igual que el formulario: no toca estado_administrativo.
          $this->update('jet_cct_tickets', $ticketId, ['estado' => 'En proceso', 'estado_comercial' => 'En cierre', 'cct_modified' => $nowSql]);
          $this->insert('jet_cct_historial_del_ticket', $sender, $nowSql, [
            'id_ticket' => (string) $ticketId, 'id_empleado' => $sender['employee_id'], 'nombre' => $sender['name'], 'correo' => $sender['email'],
            'fecha' => (string) $now, 'id_hoja_cierre' => (string) $closingId, 'fue_editada' => 'Si',
            'respuesta' => '<p>Apreciado <b>' . htmlspecialchars((string) $values['arrendatario'], ENT_QUOTES, 'UTF-8') . '</b><br>Se ha realizado la hoja cierre de este negocio para poder coordinar la revision y posteriormente la entrega del inmueble.</p>',
          ]);
        }
      }
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    $resubmitted ??= false;
    $workflow = new ClosingSheetWorkflow($this->db);
    return [
      'id' => $closingId,
      'mode' => $context['mode'],
      'url' => self::publicUrl($closingId),
      'edit_url' => 'hoja-cierre.php?' . http_build_query(['id_cierre' => $closingId], '', '&', PHP_QUERY_RFC3986),
      'synced' => $synced,
      'resubmitted' => $resubmitted,
      'notified' => $creating ? $this->notifySafely($closingId, $sender) : ($resubmitted ? $workflow->notifyResubmitted($closingId, $sender['name']) : 0),
    ];
  }

  /** La hoja ya quedó guardada: un fallo en la cola de correos solo se registra. @param array<string,string> $sender */
  private function notifySafely(int $closingId, array $sender): int
  {
    try {
      return (new ClosingSheetWorkflow($this->db))->notifyCreated($closingId, $sender);
    } catch (\Throwable $error) {
      error_log('[closing_sheet_notify] #' . $closingId . ': ' . $error->getMessage());
      return 0;
    }
  }

  /** Vista de la hoja en el panel (pide sesión; los avisos llevan el enlace firmado de cada funcionario). */
  public static function publicUrl(int $closingId): string
  {
    return ClosingSheetAccess::url(ClosingSheetAccess::VIEW, $closingId);
  }

  /**
   * Valida lo enviado contra los campos visibles. Devuelve los valores listos para guardar (listas como arreglos) y
   * los porcentajes en `__percents` (índice del mandato => texto).
   * @param array<string,mixed> $input @param array<string,mixed> $context
   * @return array<string,mixed>
   */
  public function validate(array $input, array $context, bool $creating): array
  {
    $options = $this->options();
    $errors = [];
    $values = [];
    foreach ($this->fields() as $field) {
      $name = $field['name'];
      if (!empty($field['readonly'])) {
        // Vienen del mandato (crear) o de la hoja guardada (editar); no se aceptan del navegador.
        $values[$name] = $context['values'][$name] ?? '';
        continue;
      }
      $raw = $input[$name] ?? '';
      $values[$name] = match ($field['type']) {
        'checkbox' => array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string) $v), is_array($raw) ? $raw : []), static fn(string $v): bool => $v !== ''))),
        'repeater' => $this->repeaterRows($name, is_array($raw) ? $raw : []),
        default => trim((string) (is_array($raw) ? '' : $raw)),
      };
    }
    $errors = array_merge($errors, self::normalizeTerms($values));
    foreach ($this->fields() as $field) {
      $name = $field['name'];
      if ($name === 'iva_total_precio' && $values['iva_total_precio'] === 'No aplica') {
        continue;
      }
      if (!self::visible($field, $values)) {
        $values[$name] = in_array($field['type'], ['checkbox', 'repeater'], true) ? [] : '';
        continue;
      }
      if (!empty($field['readonly'])) {
        continue;
      }
      $value = $values[$name];
      $empty = is_array($value) ? $value === [] : $value === '';
      // Al editar, un campo que la hoja guardada tenía vacío (hojas anteriores a la pregunta) puede seguir vacío.
      $saved = $context['values'][$name] ?? '';
      $wasEmpty = !$creating && (is_array($saved) ? $saved === [] : trim((string) $saved) === '');
      if (!empty($field['required']) && $empty && !$wasEmpty) {
        $errors[] = 'Falta: ' . $field['label'] . '.';
        continue;
      }
      $allowed = $this->allowed($field, $options);
      if ($allowed !== null && !$empty) {
        // Un valor que ya estaba guardado se acepta aunque el glosario haya cambiado.
        $allowed = array_merge($allowed, array_map('strval', (array) ($context['values'][$name] ?? [])));
        foreach ((array) $value as $one) {
          if (!in_array($one, $allowed, true)) {
            $errors[] = $field['label'] . ': "' . $one . '" no es una opción válida.';
          }
        }
      }
      if ($field['type'] === 'number' && !$empty && !is_numeric(str_replace(',', '.', (string) $value))) {
        $errors[] = $field['label'] . ' debe ser un número.';
      }
      if (str_starts_with($name, 'correo') && !$empty && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = $field['label'] . ': el correo no es válido.';
      }
    }
    // Acuerdos: solo se guarda el texto de las partes marcadas.
    foreach (self::AGREEMENTS as $party => $column) {
      if (!in_array($party, (array) $values['acuerdos_precontractuales'], true)) {
        $values[$column] = '';
      }
    }
    // Lista de chequeo. Al editar, los ítems que la hoja guardada tenía vacíos (hojas anteriores a la lista) pueden
    // seguir vacíos, y las observaciones que no se cambiaron conservan el HTML guardado tal cual.
    $saved = $creating ? [] : $context['values'];
    $states = $options['estado_checklist'];
    foreach (ClosingSheetForm::CHECKLIST as $notesColumn => [$group, $when, $items]) {
      $show = $when === null || self::visible(['when' => $when], $values);
      $notes = [];
      $savedNotes = [];
      foreach ($items as [$column, $text]) {
        $state = $show ? trim((string) ($input[$column] ?? '')) : '';
        $optional = !$creating && trim((string) ($saved[$column] ?? '')) === '';
        if ($show && $state === '' && !$optional) {
          $errors[] = 'Lista de chequeo (' . $group . '): falta "' . mb_strimwidth($text, 0, 70, '…', 'UTF-8') . '".';
        } elseif ($state !== '' && $states !== [] && !in_array($state, $states, true) && $state !== trim((string) ($saved[$column] ?? ''))) {
          $errors[] = 'Lista de chequeo: "' . $state . '" no es un estado válido.';
        }
        $values[$column] = $state;
        $notes[] = $show ? trim(str_replace("\r\n", "\n", (string) (($input['notas'] ?? [])[$column] ?? ''))) : '';
        $savedNotes[] = (string) ($saved['__notes'][$column] ?? '');
      }
      $values[$notesColumn] = !$creating && $show && $notes === $savedNotes
        ? (string) ($saved[$notesColumn] ?? '')
        : self::checklistNotes(array_column($items, 2), $notes);
    }
    try {
      $values['__percents'] = self::percents($context['owners'], is_array($input['porcentaje'] ?? null) ? $input['porcentaje'] : []);
    } catch (\InvalidArgumentException $error) {
      $errors[] = $error->getMessage();
    }
    if ($errors !== []) {
      throw new \DomainException(implode("\n", array_slice(array_unique($errors), 0, 12)));
    }
    return $values;
  }

  /** @param array<string,mixed> $field @param array<string,mixed> $values */
  public static function visible(array $field, array $values): bool
  {
    foreach ($field['when'] ?? [] as [$other, $accepted]) {
      $current = $values[$other] ?? '';
      $current = is_array($current) ? $current : [(string) $current];
      if (array_intersect($current, $accepted) === []) {
        return false;
      }
    }
    return true;
  }

  /**
   * Observaciones de un grupo de la lista de chequeo con el mismo HTML que dejaba el formulario: vacío si no hay
   * ninguna; si hay alguna, todos los ítems con su etiqueta.
   * @param list<string> $labels @param list<string> $notes
   */
  public static function checklistNotes(array $labels, array $notes): string
  {
    if (array_filter($notes, static fn($note): bool => trim((string) $note) !== '') === []) {
      return '';
    }
    $out = '';
    foreach ($labels as $i => $label) {
      $note = trim(str_replace("\r\n", "\n", (string) ($notes[$i] ?? '')));
      $out .= '<p><b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b>'
        . ($note !== '' ? '<br />' . str_replace("\n", "<br />\n", htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) : '') . "</p>\n";
    }
    return $out;
  }

  /**
   * Observación de cada ítem a partir del HTML guardado en una columna ob_* (lo contrario de checklistNotes).
   * @param list<string> $labels @return list<string>
   */
  public static function checklistNotesParse(array $labels, string $html): array
  {
    $notes = [];
    foreach ($labels as $label) {
      $note = '';
      // La etiqueta puede estar escapada (hojas nuevas) o tal cual (hojas del formulario de WordPress).
      foreach (array_unique([htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), $label]) as $text) {
        if (preg_match('~<b>\s*' . preg_quote($text, '~') . '\s*</b>(.*?)</p>~su', $html, $match) === 1) {
          $body = preg_replace('~^\s*<br\s*/?>\s*~i', '', $match[1]) ?? '';
          $body = preg_replace('~<br\s*/?>\s*~i', "\n", $body) ?? '';
          $note = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
          break;
        }
      }
      $notes[] = $note;
    }
    return $notes;
  }

  /**
   * Porcentaje de cada propietario (índice del mandato => texto). Un solo propietario: 100. Varios: cada uno mayor que
   * 0, máximo 2 decimales y la suma exactamente 100.
   * @param list<array<string,mixed>> $owners @param array<mixed> $posted
   * @return array<int,string>
   */
  public static function percents(array $owners, array $posted): array
  {
    if (count($owners) <= 1) {
      return $owners === [] ? [] : [(int) $owners[0]['index'] => '100'];
    }
    $out = [];
    $sum = 0.0;
    foreach ($owners as $owner) {
      $index = (int) $owner['index'];
      $text = str_replace(',', '.', str_replace(['%', ' '], '', trim((string) ($posted[$index] ?? ''))));
      if ($text === '' || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) !== 1 || (float) $text <= 0) {
        throw new \InvalidArgumentException('Escribe el porcentaje de participación de ' . $owner['name'] . ': un número mayor que 0 con máximo 2 decimales.');
      }
      $sum += (float) $text;
      $out[$index] = self::percentText((float) $text);
    }
    if (abs($sum - 100) > 0.001) {
      throw new \InvalidArgumentException('Los porcentajes de participación suman ' . str_replace('.', ',', self::percentText($sum)) . ' %: deben sumar 100 %.');
    }
    return $out;
  }

  private static function percentText(float $value): string
  {
    return rtrim(rtrim(number_format(round($value, 2), 2, '.', ''), '0'), '.');
  }

  /**
   * Deja a cada copropietario con ficha en Propietarios (la busca por documento o la crea) y con su porcentaje.
   * @param list<array<string,mixed>> $owners @param array<int,string> $percents @param array<string,string> $sender
   * @return list<array<string,mixed>>
   */
  private function registerOwners(array $owners, array $percents, array $sender, string $nowSql): array
  {
    $table = $this->db->table('jet_cct_propietarios');
    foreach ($owners as &$owner) {
      $owner['percent'] = $percents[(int) $owner['index']] ?? '';
      if ($owner['owner_id'] !== '' || $owner['document'] === '' || !$this->schema->tableExists($table)) {
        continue;
      }
      foreach ($this->db->getResults("SELECT `_ID`, `documento` FROM `{$table}`") as $row) {
        if (ContractCoOwners::sameDocument((string) $row['documento'], $owner['document'])) {
          $owner['owner_id'] = (string) $row['_ID'];
          break;
        }
      }
      if ($owner['owner_id'] === '') {
        $owner['owner_id'] = (string) $this->insert('jet_cct_propietarios', $sender, $nowSql, [
          'nombre' => $owner['name'], 'documento' => $owner['document'], 'correo' => $owner['email'], 'celular' => $owner['phone'],
        ]);
      }
    }
    unset($owner);
    return $owners;
  }

  /** @param array<string,mixed> $values @param list<array<string,mixed>> $owners @return array<string,string> */
  private function closingColumns(array $values, array $owners): array
  {
    $data = [];
    foreach ($values as $column => $value) {
      if (!str_starts_with($column, '__')) {
        $data[$column] = is_array($value) ? ($value === [] ? '' : serialize($value)) : (string) $value;
      }
    }
    if (($data['id_pph'] ?? '') !== '') {
      $label = (string) ($this->options()['club_pph'][$data['id_pph']] ?? '');
      [$card, $name] = array_pad(explode(' · ', $label, 2), 2, '');
      $data['tarjeta_pph'] = $card;
      $data['nombre_pph'] = $name;
    }
    $data['propietarios'] = (string) max(1, count($owners));
    for ($i = 1; $i <= ContractCoOwners::MAX; $i++) {
      $data['porcentaje_' . $i] = '';
      if ($i > 1) {
        foreach (self::CO_OWNER_COLUMNS as $prefix) {
          $data[$prefix . $i] = '';
        }
      }
    }
    foreach ($owners as $owner) {
      $i = (int) $owner['index'];
      $data['porcentaje_' . $i] = (string) $owner['percent'];
      if ($i > 1) {
        $data['id_propietario_' . $i] = (string) $owner['owner_id'];
        $data['propietario_' . $i] = (string) $owner['name'];
        $data['documento_propietario_' . $i] = (string) $owner['document'];
        $data['correo_propietario_' . $i] = (string) $owner['email'];
        $data['celular_propietario_' . $i] = (string) $owner['phone'];
        $data['tipo_persona_propietario_' . $i] = (string) $owner['type'];
        $data['tipo_documento_propietario_' . $i] = (string) $owner['document_type'];
        $data['representante_propietario_' . $i] = (string) $owner['representative'];
        $data['documento_representante_propietario_' . $i] = (string) $owner['representative_document'];
      }
    }
    return $data;
  }

  /**
   * Escribe en el mandato todo dato de la hoja que el mandato también tiene.
   * @param array<string,mixed> $mandate @param array<string,mixed> $values @param list<array<string,mixed>> $owners
   * @return list<string> columnas del mandato que cambiaron
   */
  private function syncMandate(array $mandate, array $values, array $owners, int $newClosingId, string $nowSql): array
  {
    $data = [];
    foreach (self::MANDATE_TERMS as $column => $target) {
      $value = trim((string) ($values[$column] ?? ''));
      if ($value !== '') {
        $data[$target] = self::mandateTerm($column, $value, (string) ($mandate[$target] ?? ''));
      }
    }
    foreach (self::MANDATE_SYNC as $column => $target) {
      $value = $values[$column] ?? '';
      $isEmpty = is_array($value) ? $value === [] : $value === '';
      // Campos ocultos (por ejemplo la copropiedad de un inmueble que no es PH) no borran lo que tiene el mandato.
      $field = $this->field($column);
      if ($field !== null && !self::visible($field, $values) && $isEmpty) {
        continue;
      }
      $data[$target] = is_array($value) ? ($value === [] ? '' : serialize($value)) : (string) $value;
    }
    foreach ($owners as $owner) {
      $i = (int) $owner['index'];
      $data['porcentaje_' . $i] = (string) $owner['percent'];
      if ($owner['owner_id'] !== '') {
        $data['id_propietario_nuevo_' . $i] = (string) $owner['owner_id'];
      }
    }
    if ($newClosingId > 0) {
      $data['id_hoja_cierre'] = (string) $newClosingId;
      $data['estado_hoja_cierre'] = self::STATUS_NEW;
    }
    $table = $this->db->table('jet_cct_contrato_mandato');
    $data = $this->schema->filterTableData($table, $data);
    $changed = array_keys(array_filter($data, static fn($value, $column): bool => (string) ($mandate[$column] ?? '') !== (string) $value, ARRAY_FILTER_USE_BOTH));
    if ($changed !== []) {
      $this->db->update($table, array_intersect_key($data, array_flip($changed)) + $this->schema->filterTableData($table, ['cct_modified' => $nowSql]), ['_ID' => (int) $mandate['_ID']]);
    }
    return array_values(array_diff($changed, ['id_hoja_cierre', 'estado_hoja_cierre']));
  }

  /** Copropiedad, inmueble (CCT y post de WordPress) y arrendatario, como las acciones del formulario. @param array<string,string> $ids @param array<string,mixed> $values */
  private function syncRecords(array $ids, array $values, int $newClosingId): void
  {
    $text = static fn($value): string => is_array($value) ? ($value === [] ? '' : serialize($value)) : (string) $value;
    $visibleValues = [];
    foreach ($values as $column => $value) {
      $field = $this->field($column);
      if ($field === null || self::visible($field, $values)) {
        $visibleValues[$column] = $text($value);
      }
    }
    $pick = static fn(array $map) => array_filter(array_combine(array_values($map), array_map(static fn($column) => $visibleValues[$column] ?? null, array_keys($map))), static fn($value): bool => $value !== null);

    if ((int) $ids['id_copropiedad'] > 0 && ($values['propiedad_horizontal'] ?? '') === 'Si') {
      $this->update('jet_cct_copropiedades', (int) $ids['id_copropiedad'], $pick(self::BUILDING_SYNC));
    }
    $schedules = array_combine(self::SCHEDULES, self::SCHEDULES);
    if ((int) $ids['id_inmueble_data'] > 0) {
      $property = $pick(self::PROPERTY_SYNC + $schedules);
      if ($newClosingId > 0) {
        $property += ['id_arrendatario' => $ids['id_arrendatario'], 'arrendatario' => (string) $values['arrendatario'], 'id_hoja_cierre' => (string) $newClosingId, 'estado' => 'En cierre'];
      }
      $this->update('jet_cct_inmuebles', (int) $ids['id_inmueble_data'], $property);
    }
    $meta = $pick(self::POST_META + array_combine(self::SCHEDULES, array_map(static fn(string $column): string => str_replace('_', '-', $column), self::SCHEDULES)));
    if ($newClosingId > 0) {
      $meta += ['id_hoja_cierre' => (string) $newClosingId, 'id_copropiedad' => $ids['id_copropiedad']];
    }
    $this->propertyMeta((int) $ids['id_inmueble'], $meta);
    if ((int) $ids['id_arrendatario'] > 0) {
      $this->update('jet_cct_arrendatarios', (int) $ids['id_arrendatario'], array_filter([
        'nombre' => (string) $values['arrendatario'], 'documento' => (string) $values['documento_arrendatario'],
        'correo' => (string) $values['correo_arrendatario'], 'celular' => (string) $values['celular_arrendatario'],
        'tipo_persona' => (string) $values['tipo_persona_arrendatario'], 'tipo_documento' => (string) $values['tipo_documento_arrendatario'],
        'nombre_juridico' => (string) $values['representante_arrendatario'], 'documento_juridico' => (string) $values['documento_representante_arrendatario'],
      ], static fn(string $value): bool => $value !== ''));
    }
  }

  /** Evita guardar dos veces la misma hoja con un doble clic. @param array<string,string> $ids */
  private function recentDuplicate(array $ids, int $now): bool
  {
    $table = $this->db->table('jet_cct_cierres');
    return (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE `id_contrato` = ? AND `id_arrendatario` = ? AND CAST(`fecha` AS UNSIGNED) >= ?", [$ids['id_contrato'], $ids['id_arrendatario'], $now - 120]) > 0;
  }

  /** @param array<string,mixed> $field @param array<string,mixed> $options @return list<string>|null */
  private function allowed(array $field, array $options): ?array
  {
    $source = $field['options'] ?? null;
    if (is_array($source)) {
      return array_is_list($source) ? $source : array_map('strval', array_keys($source));
    }
    if (!is_string($source) || !isset($options[$source]) || $options[$source] === []) {
      return null;
    }
    return $source === 'club_pph' ? array_map('strval', array_keys($options[$source])) : $options[$source];
  }

  /** @param array<mixed> $rows @return list<array<string,string>> */
  private function repeaterRows(string $name, array $rows): array
  {
    $keys = array_column(ClosingSheetForm::REPEATERS[$name] ?? [], 'name');
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $clean = [];
      foreach ($keys as $key) {
        $clean[$key] = trim((string) (is_array($row[$key] ?? null) ? '' : ($row[$key] ?? '')));
      }
      if (implode('', $clean) !== '') {
        $out[] = $clean;
      }
    }
    return $out;
  }

  /** @return array<string,mixed>|null */
  private function field(string $name): ?array
  {
    static $byName = null;
    if ($byName === null) {
      $byName = array_column($this->fields(), null, 'name');
    }
    return $byName[$name] ?? null;
  }

  /** Valor guardado comparado sin mayúsculas contra las opciones del glosario (como el script del formulario). */
  private function match(string $optionsKey, string $value): string
  {
    foreach ($this->options()[$optionsKey] ?? [] as $option) {
      if (strcasecmp($option, $value) === 0) {
        return $option;
      }
    }
    return $value;
  }

  /** @param array<int,mixed> $values */
  private function firstId(array $values): string
  {
    foreach ($values as $value) {
      $value = trim((string) $value);
      if (ctype_digit($value) && (int) $value > 0) {
        return $value;
      }
    }
    return '';
  }

  /** @return array<int,mixed> */
  private function list(mixed $value): array
  {
    if (is_array($value)) {
      return array_values($value);
    }
    $text = trim((string) $value);
    if ($text === '') {
      return [];
    }
    $decoded = preg_match('/^a:\d+:\{/', $text) === 1 ? @unserialize($text, ['allowed_classes' => false]) : json_decode($text, true);
    return is_array($decoded) ? array_values($decoded) : [];
  }

  /** @return list<string> */
  private function glossary(int $id): array
  {
    $table = $this->db->table('jet_post_types');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $items = @unserialize((string) ($this->db->getVar("SELECT `meta_fields` FROM `{$table}` WHERE `id` = ? AND `status` = 'glossary' LIMIT 1", [$id]) ?? ''), ['allowed_classes' => false]);
    $out = [];
    foreach (is_array($items) ? $items : [] as $item) {
      // "Cumple " del glosario de la lista de chequeo se guarda sin el espacio final.
      $value = trim((string) ($item['value'] ?? ''));
      if ($value !== '') {
        $out[$value] = $value;
      }
    }
    return array_values($out);
  }
}
