<?php

namespace SCM\Support\TimelineMaps;

final class EntregaTimelineMap
{
  /**
   * @return array<int,array<string,mixed>>
   */
  public static function steps(): array
  {
    return [
      [
        'key'        => 'tarea_generada',
        'label'      => 'Tarea generada',
        'icon'       => '01',
        'ts_field'   => 'ticket_cct_created',
        'done_if'    => 'always',
        'empty_text' => 'Sin fecha de tarea',
      ],
      [
        'key'                 => 'cita_agendada',
        'label'               => 'Cita agendada',
        'icon'                => '02',
        'ts_field'            => 'cita_fecha_inicio',
        'done_if'             => 'field_not_empty',
        'done_field'          => 'cita_fecha_inicio',
        'elapsed_from_fields' => ['ticket_cct_created'],
        'elapsed_from_label'  => 'Tarea generada',
        'link_url'            => 'https://calendar-skc.netlify.app/evento/{id_cita_agendada}',
        'empty_text'          => 'Cita sin agendar',
      ],
      [
        'key'                 => 'revision_antes_entrega',
        'label'               => 'Revisión antes de la entrega',
        'icon'                => '03',
        'ts_field'            => 'entrega_rev_fecha',
        'done_if'             => 'field_not_empty',
        'done_field'          => 'entrega_rev_fecha',
        'elapsed_from_fields' => ['cita_fecha_inicio', 'ticket_cct_created'],
        'elapsed_from_label'  => 'Cita agendada',
        'link_url'            => 'https://sucasainmobiliaria.com.co/revision-de-entrega/?numero={id_revision_entrega}',
        'empty_text'          => 'Sin revisión antes de la entrega',
      ],
      [
        'key'                 => 'inventario_registrado',
        'label'               => 'Inventario registrado',
        'icon'                => '04',
        'ts_field'            => 'entrega_inventario_fecha',
        'done_if'             => 'field_not_empty',
        'done_field'          => 'entrega_inventario_fecha',
        'elapsed_from_fields' => ['entrega_rev_fecha'],
        'elapsed_from_label'  => 'Revisión antes de la entrega',
        'link_url'            => 'https://sucasainmobiliaria.com.co/inventario-inmueble/?numero={id_inventario_entrega}',
        'empty_text'          => 'Sin inventario registrado',
      ],
      [
        'key'                 => 'inmueble_entregado',
        'label'               => 'Inmueble entregado',
        'icon'                => '05',
        'ts_field'            => 'entrega_acta_fecha',
        'done_if'             => 'field_not_empty',
        'done_field'          => 'entrega_acta_fecha',
        'elapsed_from_fields' => ['entrega_inventario_fecha'],
        'elapsed_from_label'  => 'Inventario registrado',
        'link_url'            => 'https://sucasainmobiliaria.com.co/acta-de-entrega/?numero={id_acta_entrega}',
        'empty_text'          => 'Inmueble sin entrega',
      ],
    ];
  }
}
