<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Modules\Pending\PublicServicesUi as UI;

/** Formulario "Crear contrato de arrendamiento" para los contratos "No asignado". */
final class LeaseContractCreateView
{
  private const REQ = ' <em class="!sp-text-rose-600 !sp-not-italic">*</em>';
  private const SECTION = '!sp-bg-white !sp-rounded-xl !sp-border !sp-border-solid !sp-border-slate-200 !sp-shadow-card !sp-p-5 !sp-m-0 !sp-min-w-0';
  private const LEGEND = '!sp-float-left !sp-w-full !sp-p-0 !sp-mb-3 !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide';
  private const GRID = '!sp-clear-both !sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-3 !sp-gap-3';
  private const SMALL_BUTTON = '!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-2.5 !sp-py-1.5 !sp-rounded-lg !sp-bg-white !sp-text-service-navy !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-[10px] !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-slate-50';

  /**
   * @param array<string,mixed> $contract
   * @param array<string,mixed> $v valores iniciales (cierre, propietario, arrendatario, copropiedad)
   * @param array<string,string[]> $o opciones de glosarios
   */
  public function render(array $contract, array $v, array $o): string
  {
    $ui = UI::class;
    $closingNote = ($v['_closing_id'] ?? '') !== ''
      ? '<p class="!sp-m-0 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-emerald-200 !sp-bg-emerald-50 !sp-text-[11px] !sp-text-emerald-800">Datos cargados de la hoja de cierre #' . esc_html((string) $v['_closing_id']) . ($v['_closing_state'] !== '' ? ' (' . esc_html((string) $v['_closing_state']) . ')' : '') . '. Revísalos antes de crear el contrato; al guardar, el cierre queda como <strong>Realizada</strong>.</p>'
      : '<p class="!sp-m-0 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-amber-200 !sp-bg-amber-50 !sp-text-[11px] !sp-text-amber-900">Este contrato no tiene hoja de cierre vinculada. Completa los datos manualmente.</p>';

    $html = '<form class="!sp-space-y-4" data-lease-create-form autocomplete="off" novalidate>'
      . '<input type="hidden" name="contract_id" value="' . esc_attr((string) ($contract['_ID'] ?? '')) . '">' . $closingNote;

    $html .= $this->section('Contrato e inmueble', ''
      . $this->text('contrato', '# Contrato' . self::REQ, '', ' required maxlength="30" placeholder="Número del contrato"')
      . $this->text('inmueble', '# Inmueble SIMI' . self::REQ, (string) $v['inmueble'], ' required maxlength="30"')
      . $this->text('direccion', 'Dirección' . self::REQ, (string) $v['direccion'], ' required', 'sm:!sp-col-span-2 lg:!sp-col-span-1')
      . $this->select('tipo_inmueble', 'Tipo de inmueble' . self::REQ, $o['tipo_inmueble'], (string) $v['tipo_inmueble'], true)
      . $this->select('destinacion_inmueble', 'Destinación' . self::REQ, $o['destinacion_inmueble'], (string) $v['destinacion_inmueble'], true)
      . $this->select('gestion_inmueble', 'Gestión' . self::REQ, $o['gestion_inmueble'], (string) $v['gestion_inmueble'], true)
      . '<label class="!sp-block sm:!sp-col-span-2 lg:!sp-col-span-3"><span class="' . $ui::LABEL . '">Registro fotográfico' . self::REQ . '</span><div class="!sp-flex !sp-flex-wrap !sp-gap-2"><input class="' . $ui::INPUT . ' !sp-flex-1 !sp-min-w-[220px]" name="registro_fotografico" type="url" required value="' . esc_attr((string) $v['registro_fotografico']) . '" placeholder="https://drive.google.com/..." data-lease-photo-input>'
        . '<a class="' . self::SMALL_BUTTON . (filter_var($v['registro_fotografico'], FILTER_VALIDATE_URL) ? '' : ' !sp-hidden') . '" href="' . esc_attr(filter_var($v['registro_fotografico'], FILTER_VALIDATE_URL) ? (string) $v['registro_fotografico'] : '#') . '" target="_blank" rel="noopener noreferrer" data-lease-photo-link>Abrir carpeta</a>'
        . '<a class="' . self::SMALL_BUTTON . '" href="https://drive.google.com/drive/u/1/folders/1gqJf1QJcd2zxF4yZF46ZeU-I8JbKCwBi" target="_blank" rel="noopener noreferrer">Drive de registros fotográficos</a></div></label>');

    $html .= $this->section('Condiciones del contrato', ''
      . $this->text('valor_canon', 'Canon' . self::REQ, (string) $v['valor_canon'], ' required inputmode="numeric"')
      . $this->text('valor_administracion', 'Administración' . self::REQ, (string) $v['valor_administracion'], ' required inputmode="numeric"')
      . $this->text('tasa_administracion', 'Tasa de administración (%)' . self::REQ, (string) $v['tasa_administracion'], ' required')
      . $this->text('derechos_inmobiliarios', 'Derechos inmobiliarios (%)' . self::REQ, (string) $v['derechos_inmobiliarios'], ' required')
      . $this->text('incremento_pactado', 'Incremento pactado' . self::REQ, (string) $v['incremento_pactado'], ' required placeholder="Ej: IPC +2"')
      . $this->text('meses_contrato', 'Meses de contrato' . self::REQ, (string) $v['meses_contrato'], ' required inputmode="numeric"')
      . $this->text('dias_gracia', 'Días de gracia', (string) $v['dias_gracia'], ' inputmode="numeric" placeholder="0"')
      . $this->checks('servicios_publicos', 'Servicios públicos', $o['servicios_publicos'], (array) $v['servicios_publicos'], 'sm:!sp-col-span-2')
      . $this->text('luz', 'Energía · NIC / medidor', (string) $v['luz'])
      . $this->text('agua', 'Agua · póliza / medidor', (string) $v['agua'])
      . $this->text('gas', 'Gas · cuenta / medidor', (string) $v['gas']));

    $html .= $this->section('Parqueadero y depósito', ''
      . $this->select('tiene_parqueadero', '¿Tiene parqueadero?' . self::REQ, $o['si_no'], (string) $v['tiene_parqueadero'], true)
      . $this->when('tiene_parqueadero', 'Si', $this->select('tipo_parqueadero', 'Tipo de parqueadero' . self::REQ, $o['tipo_parqueadero'], (string) $v['tipo_parqueadero'])
        . $this->text('parqueaderos', 'Cantidad de parqueaderos' . self::REQ, (string) $v['parqueaderos'])
        . $this->text('numero_parqueadero', '# Parqueadero' . self::REQ, (string) $v['numero_parqueadero'], ' placeholder="Separa con coma si son varios"'), true)
      . $this->select('tiene_deposito', '¿Tiene depósito?' . self::REQ, $o['si_no'], (string) $v['tiene_deposito'], true)
      . $this->when('tiene_deposito', 'Si', $this->text('depositos', 'Cantidad de depósitos' . self::REQ, (string) $v['depositos']), true));

    $html .= $this->section('Propiedad horizontal', ''
      . $this->select('propiedad_horizontal', '¿Pertenece a régimen de propiedad horizontal?' . self::REQ, $o['si_no'], (string) $v['propiedad_horizontal'], true, 'sm:!sp-col-span-2')
      . $this->when('propiedad_horizontal', 'Si', ''
        . $this->text('copropiedad', 'Copropiedad' . self::REQ, (string) $v['copropiedad'])
        . $this->text('nit_copropiedad', 'NIT' . self::REQ, (string) $v['nit_copropiedad'])
        . $this->text('administrador', 'Administrador' . self::REQ, (string) $v['administrador'])
        . $this->text('correo_copropiedad', 'Correo de la copropiedad' . self::REQ, (string) $v['correo_copropiedad'], ' type-email')
        . $this->text('celular_copropiedad', 'Celular de la copropiedad' . self::REQ, (string) $v['celular_copropiedad'], ' inputmode="tel"')
        . $this->checks('zonas_sociales', 'Amenidades de la copropiedad', $o['zonas_sociales'], (array) $v['zonas_sociales'], 'sm:!sp-col-span-2 lg:!sp-col-span-3')
        . '<div class="sm:!sp-col-span-2 lg:!sp-col-span-3"><span class="' . $ui::LABEL . '">Horarios</span><div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-3">'
        . $this->timeRange('Mudanza · franja 1 (lun a vie)' . self::REQ, 'horario_mudanza_inicio_1', 'horario_mudanza_final_1', $v)
        . $this->timeRange('Mudanza · franja 2 (lun a vie)' . self::REQ, 'horario_mudanza_inicio_2', 'horario_mudanza_final_2', $v)
        . $this->timeRange('Mudanza · sábado (vacío si no aplica)', 'horario_mudanza_inicio_sabado', 'horario_mudanza_final_sabado', $v)
        . $this->timeRange('Zonas sociales', 'horario_zonas_inicio', 'horario_zonas_final', $v)
        . $this->timeRange('Gimnasio', 'horario_gym_inicio', 'horario_gym_final', $v)
        . $this->timeRange('Piscina', 'horario_piscina_inicio', 'horario_piscina_final', $v)
        . $this->timeRange('Canchas', 'horario_canchas_inicio', 'horario_canchas_final', $v)
        . '</div></div>', true));

    $html .= '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-4">' . $this->person('propietario', 'Propietario', $v) . $this->person('arrendatario', 'Arrendatario', $v) . '</div>';

    $html .= '<div data-lease-when="propiedad_horizontal=Si">' . $this->section('Información adicional del arrendatario (para la copropiedad)', ''
      . $this->select('tiene_mascotas', '¿Tiene mascotas?' . self::REQ, $o['si_no'], (string) $v['tiene_mascotas'], true)
      . $this->select('tiene_vehiculo', '¿Tiene vehículo?' . self::REQ, $o['si_no'], (string) $v['tiene_vehiculo'], true)
      . $this->select('convive_personas', '¿Convive con personas?' . self::REQ, $o['si_no'], (string) $v['convive_personas'], true)
      . $this->when('tiene_mascotas', 'Si', $this->repeater('mascotas', 'Mascotas', 'Agregar mascota', (array) $v['mascotas'], [['tipo', 'Tipo', $o['tipo_mascota']], ['raza', 'Raza', null]]), true)
      . $this->when('tiene_vehiculo', 'Si', $this->repeater('vehiculos', 'Vehículos', 'Agregar vehículo', (array) $v['vehiculos'], [['tipo', 'Tipo', $o['tipo_vehiculo']], ['placa', 'Placa', null]]), true)
      . $this->when('convive_personas', 'Si', $this->repeater('personas', 'Personas que conviven', 'Agregar persona', (array) $v['personas'], [['nombre', 'Nombre', null], ['edad', 'Edad', null], ['identificacion', 'Identificación', null], ['parentesco', 'Parentesco', $o['parentesco']], ['correo', 'Correo', null], ['celular', 'Celular', null], ['ocupacion', 'Ocupación', $o['ocupacion']]]), true)) . '</div>';

    $codebtors = in_array((string) $v['num_codeudores'], ['1', '2', '3', '4'], true) ? (string) $v['num_codeudores'] : 'Ninguno';
    $html .= $this->section('Codeudores', $this->select('num_codeudores', 'Número de codeudores' . self::REQ, ['Ninguno', '1', '2', '3', '4'], $codebtors, false, '', ' data-lease-codebtors'));
    $html .= '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-4">';
    for ($i = 1; $i <= 4; $i++) {
      $html .= $this->person('codeudor_' . $i, 'Codeudor ' . $i, $v, true);
    }
    $html .= '</div>';

    $agreements = '';
    foreach (['Propietario' => 'acuerdos_precontractuales_pro', 'Arrendatario' => 'acuerdos_precontractuales_arre', 'Copropiedad' => 'acuerdos_precontractuales_co', 'Inmobiliaria' => 'acuerdos_precontractuales_inm', 'Externo' => 'acuerdos_precontractuales_ext'] as $option => $field) {
      $agreements .= '<label class="!sp-block !sp-hidden" data-lease-agreement="' . esc_attr($option) . '"><span class="' . $ui::LABEL . '">Acuerdos con ' . esc_html(strtolower($option)) . '</span><textarea class="' . $ui::INPUT . ' !sp-h-auto !sp-py-2" name="' . $field . '" rows="3">' . esc_html((string) ($v[$field] ?? '')) . '</textarea></label>';
    }
    $html .= $this->section('Acuerdos precontractuales', $this->checks('acuerdos_precontractuales', 'Marca con quién se hizo un preacuerdo', $o['acuerdos_precontractuales'], (array) $v['acuerdos_precontractuales'], 'sm:!sp-col-span-2 lg:!sp-col-span-3', ' data-lease-agreements') . '<div class="sm:!sp-col-span-2 lg:!sp-col-span-3 !sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-3">' . $agreements . '</div>');

    $html .= '<div class="' . self::SECTION . ' !sp-text-[10px] !sp-leading-relaxed !sp-text-service-muted"><strong class="!sp-block !sp-mb-1 !sp-text-service-navy">Qué pasa al crear el contrato</strong>'
      . 'Se asigna el número al contrato, la hoja de cierre queda Realizada y se actualizan el contrato de mandato, los casos y la revisión de entrega, el inventario, los codeudores, las relaciones y la ficha del inmueble en la web. '
      . 'Si el inmueble pertenece a una copropiedad, se generan el acta de notificación de ingreso (al arrendatario) y la carta de presentación (a la copropiedad) y se envían por correo y WhatsApp.</div>'
      . '<p class="!sp-hidden !sp-m-0 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-rose-200 !sp-bg-rose-50 !sp-text-[11px] !sp-text-rose-700" data-lease-create-error role="alert"></p>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-2"><button type="button" class="' . $ui::SECONDARY . '" data-lease-modal-close>Cancelar</button><button type="submit" class="' . $ui::PRIMARY . '" data-lease-create-submit>' . $ui::icon('check') . 'Crear contrato de arrendamiento</button></div>'
      . '</form>';
    return $html;
  }

  /**
   * Reenvío del acta de notificación de ingreso y de la carta de presentación a la copropiedad.
   *
   * @param array<string,mixed> $c
   * @param array{notificacion:?array{url:string},administracion:?array{url:string}} $acts
   */
  public function renderResendActs(array $c, array $acts): string
  {
    $ui = UI::class;
    $v = static fn(string $key): string => trim((string) ($c[$key] ?? ''));
    $card = function (string $target, string $title, ?array $act, string $who, string $email, string $phone) use ($ui): string {
      $contact = implode(' · ', array_filter([$email, $phone]));
      $preview = $act !== null
        ? '<button type="button" class="' . self::SMALL_BUTTON . '" data-scm-open-iframe data-iframe-url="' . esc_attr($act['url']) . '" data-iframe-title="' . esc_attr($title) . '">' . $ui::icon('eye', '!sp-w-3 !sp-h-3') . 'Ver acta</button>'
        : '<span class="!sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-border !sp-border-solid !sp-border-amber-200 !sp-bg-amber-50 !sp-text-amber-800 !sp-text-[9px] !sp-font-bold">Se generará al reenviar</span>';
      return '<div class="' . self::SECTION . ' !sp-space-y-3"><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-2"><span class="' . $ui::LABEL . ' !sp-mb-0">' . esc_html($title) . '</span>'
        . $preview . '</div>'
        . '<p class="!sp-m-0 !sp-text-[11px]"><strong>' . esc_html($who !== '' ? $who : 'Sin nombre') . '</strong><br><span class="!sp-text-service-muted">' . esc_html($contact !== '' ? $contact : 'Sin correo ni celular registrado') . '</span></p>'
        . '<label class="!sp-flex !sp-items-center !sp-gap-2 !sp-text-[11px] !sp-font-semibold"><input type="checkbox" name="targets[]" value="' . $target . '"' . ($contact !== '' ? ' checked' : ' disabled') . '>Reenviar por correo y WhatsApp</label></div>';
    };
    return '<form class="!sp-space-y-4" data-lease-resend-form autocomplete="off" novalidate><input type="hidden" name="contract_id" value="' . esc_attr($v('_ID')) . '">'
      . '<p class="!sp-m-0 !sp-text-[11px] !sp-text-service-muted">Si el acta ya existe se reenvía el mismo documento; si no se generó al crear el contrato, se genera ahora con los datos del contrato y su hoja de cierre. Los datos de contacto son los del contrato; si cambiaron, actualízalos antes. El reenvío queda en el historial del contrato y del inmueble.</p>'
      . '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4">'
      . $card('arrendatario', 'Notificación de ingreso al arrendatario', $acts['notificacion'], $v('arrendatario'), $v('correo_arrendatario'), $v('celular_arrendatario'))
      . $card('copropiedad', 'Presentación del nuevo inquilino a la copropiedad', $acts['administracion'], trim($v('administrador') . ($v('copropiedad') !== '' ? ' · ' . $v('copropiedad') : ''), ' ·'), $v('correo_copropiedad'), $v('celular_copropiedad'))
      . '</div><p class="!sp-hidden !sp-m-0 !sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-rose-200 !sp-bg-rose-50 !sp-text-[11px] !sp-text-rose-700" data-lease-resend-error role="alert"></p>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-2"><button type="button" class="' . $ui::SECONDARY . '" data-lease-modal-close>Cancelar</button><button type="submit" class="' . $ui::PRIMARY . '" data-lease-resend-submit>' . $ui::icon('refresh') . 'Reenviar actas</button></div></form>';
  }

  /** @param array<string,mixed> $result */
  public function renderResult(array $result): string
  {
    $ui = UI::class;
    $queued = (array) ($result['queued'] ?? []);
    $docs = '';
    foreach ([['notificacion', 'Acta de notificación de ingreso'], ['administracion', 'Carta de presentación a la copropiedad']] as [$key, $label]) {
      if (!empty($result['documents'][$key])) {
        $docs .= '<button type="button" class="' . self::SMALL_BUTTON . '" data-scm-open-iframe data-iframe-url="' . esc_attr((string) $result['documents'][$key]) . '" data-iframe-title="' . esc_attr($label) . '">' . $ui::icon('eye', '!sp-w-3 !sp-h-3') . esc_html($label) . '</button>';
      }
    }
    return '<div class="' . self::SECTION . ' !sp-text-center !sp-space-y-3"><span class="!sp-inline-flex !sp-p-3 !sp-rounded-full !sp-bg-emerald-50 !sp-text-emerald-700">' . $ui::icon('check', '!sp-w-6 !sp-h-6') . '</span>'
      . '<p class="!sp-m-0 !sp-text-sm !sp-font-bold">Contrato #' . esc_html((string) ($result['contrato'] ?? '')) . ' creado</p>'
      . '<p class="!sp-m-0 !sp-text-[11px] !sp-text-service-muted">En cola: ' . (int) ($queued['email'] ?? 0) . ' correo(s), ' . (int) ($queued['whatsapp'] ?? 0) . ' WhatsApp y ' . (int) ($queued['internal'] ?? 0) . ' aviso(s) internos. Ahora puedes enviar los anexos del contrato desde el listado.</p>'
      . ($docs !== '' ? '<div class="!sp-flex !sp-flex-wrap !sp-justify-center !sp-gap-2">' . $docs . '</div>' : '')
      . '<div><button type="button" class="' . $ui::NAVY . '" data-lease-modal-close>Cerrar</button></div></div>';
  }

  private function section(string $title, string $body): string
  {
    return '<fieldset class="' . self::SECTION . '"><legend class="' . self::LEGEND . '">' . esc_html($title) . '</legend><div class="' . self::GRID . '">' . $body . '</div></fieldset>';
  }

  /** Bloque visible solo cuando otro campo tiene cierto valor (lo resuelve el JS del panel). */
  private function when(string $field, string $value, string $body, bool $inline = false): string
  {
    return '<div class="' . ($inline ? 'sm:!sp-col-span-2 lg:!sp-col-span-3 !sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-3 !sp-gap-3' : '') . '" data-lease-when="' . esc_attr($field . '=' . $value) . '">' . $body . '</div>';
  }

  private function text(string $name, string $label, string $value, string $attrs = '', string $extra = ''): string
  {
    $type = str_contains($attrs, 'type-email') ? 'email' : 'text';
    $attrs = str_replace(' type-email', '', $attrs);
    return '<label class="!sp-block ' . $extra . '"><span class="' . UI::LABEL . '">' . $label . '</span><input class="' . UI::INPUT . '" name="' . $name . '" type="' . $type . '" value="' . esc_attr($value) . '"' . $attrs . '></label>';
  }

  /** @param string[] $options */
  private function select(string $name, string $label, array $options, string $selected, bool $placeholder = false, string $extra = '', string $attrs = ''): string
  {
    $html = $placeholder ? '<option value="">Elige una opción</option>' : '';
    $found = false;
    foreach ($options as $option) {
      $isSelected = $option === $selected;
      $found = $found || $isSelected;
      $html .= '<option value="' . esc_attr($option) . '"' . ($isSelected ? ' selected' : '') . '>' . esc_html($option) . '</option>';
    }
    return '<label class="!sp-block ' . $extra . '"><span class="' . UI::LABEL . '">' . $label . '</span><select class="' . UI::INPUT . '" name="' . $name . '"' . $attrs . ' data-lease-watch>' . $html . '</select></label>';
  }

  /** @param string[] $options @param array<int,mixed> $selected */
  private function checks(string $name, string $label, array $options, array $selected, string $extra = '', string $attrs = ''): string
  {
    $selected = array_map('strval', $selected);
    $html = '<fieldset class="!sp-m-0 !sp-p-0 !sp-border-0 !sp-min-w-0 ' . $extra . '"' . $attrs . '><legend class="' . UI::LABEL . '">' . esc_html($label) . '</legend><div class="!sp-flex !sp-flex-wrap !sp-gap-2">';
    foreach ($options as $option) {
      $html .= '<label class="!sp-inline-flex !sp-items-center !sp-gap-1.5 !sp-px-2.5 !sp-py-1.5 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200 !sp-bg-slate-50 !sp-text-[10px] !sp-cursor-pointer has-[:checked]:!sp-bg-amber-50 has-[:checked]:!sp-border-amber-300"><input type="checkbox" name="' . $name . '[]" value="' . esc_attr($option) . '"' . (in_array($option, $selected, true) ? ' checked' : '') . '>' . esc_html($option) . '</label>';
    }
    return $html . '</div></fieldset>';
  }

  /** @param array<string,mixed> $v */
  private function timeRange(string $label, string $start, string $end, array $v): string
  {
    $input = static fn(string $name): string => '<input class="' . UI::INPUT . '" name="' . $name . '" type="time" value="' . esc_attr((string) ($v[$name] ?? '')) . '">';
    return '<div><span class="!sp-block !sp-mb-1 !sp-text-[10px] !sp-font-semibold">' . $label . '</span><div class="!sp-grid !sp-grid-cols-2 !sp-gap-2">' . $input($start) . $input($end) . '</div></div>';
  }

  /** @param array<string,mixed> $v */
  private function person(string $key, string $title, array $v, bool $withAddress = false): string
  {
    $field = fn(string $prefix, string $label, string $type = 'text'): string => $this->text($prefix . $key, $label . self::REQ, (string) ($v[$prefix . $key] ?? ''), $type === 'email' ? ' type-email' : '');
    return '<fieldset class="' . self::SECTION . '" data-lease-person="' . esc_attr($key) . '"><legend class="' . self::LEGEND . '">' . esc_html($title) . '</legend><div class="!sp-clear-both !sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 !sp-gap-3">'
      . $field('', 'Nombre') . $field('documento_', 'Documento') . $field('correo_', 'Correo', 'email') . $field('celular_', 'Celular')
      . ($withAddress ? $this->text('dir_' . $key, 'Dirección' . self::REQ, (string) ($v['dir_' . $key] ?? ''), '', 'sm:!sp-col-span-2') : '')
      . '</div></fieldset>';
  }

  /**
   * @param array<int,mixed> $rows
   * @param array<int,array{0:string,1:string,2:?array}> $fields [clave, etiqueta, opciones o null para texto]
   */
  private function repeater(string $name, string $label, string $add, array $rows, array $fields): string
  {
    $row = function (string $index, array $values) use ($name, $fields): string {
      $html = '<div class="!sp-relative !sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-2 !sp-p-3 !sp-pr-10 !sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200 !sp-bg-slate-50" data-lease-repeat-row>';
      foreach ($fields as [$key, $fieldLabel, $options]) {
        $control = $options === null
          ? '<input class="' . UI::INPUT . ' !sp-bg-white" name="' . $name . '[' . $index . '][' . $key . ']" type="text" value="' . esc_attr((string) ($values[$key] ?? '')) . '">'
          : '<select class="' . UI::INPUT . ' !sp-bg-white" name="' . $name . '[' . $index . '][' . $key . ']"><option value="">Selecciona</option>' . implode('', array_map(static fn(string $option): string => '<option value="' . esc_attr($option) . '"' . ((string) ($values[$key] ?? '') === $option ? ' selected' : '') . '>' . esc_html($option) . '</option>', $options)) . '</select>';
        $html .= '<label class="!sp-block"><span class="!sp-block !sp-mb-1 !sp-text-[9px] !sp-font-semibold !sp-uppercase !sp-text-service-muted">' . esc_html($fieldLabel) . '</span>' . $control . '</label>';
      }
      return $html . '<button type="button" class="!sp-absolute !sp-top-2 !sp-right-2 !sp-w-7 !sp-h-7 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-rose-200 !sp-text-rose-700 !sp-cursor-pointer hover:!sp-bg-rose-50" data-lease-repeat-remove aria-label="Quitar">&times;</button></div>';
    };
    $html = '<div class="sm:!sp-col-span-2 lg:!sp-col-span-3 !sp-space-y-2" data-lease-repeat="' . esc_attr($name) . '"><span class="' . UI::LABEL . '">' . esc_html($label) . '</span><div class="!sp-space-y-2" data-lease-repeat-list>';
    foreach (array_values($rows) as $index => $values) {
      $html .= $row((string) $index, is_array($values) ? $values : []);
    }
    return $html . '</div><template data-lease-repeat-template>' . $row('__i__', []) . '</template><button type="button" class="' . self::SMALL_BUTTON . '" data-lease-repeat-add>+ ' . esc_html($add) . '</button></div>';
  }
}
