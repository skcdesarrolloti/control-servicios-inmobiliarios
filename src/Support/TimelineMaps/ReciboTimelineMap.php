<?php

namespace SCM\Support\TimelineMaps;

final class ReciboTimelineMap
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
        'key'                 => 'revision_recibo',
        'label'               => 'Revisión antes de la desocupación',
        'icon'                => '03',
        'ts_field'            => 'recibo_rev_fecha',
        'done_if'             => 'field_not_empty',
        'done_field'          => 'recibo_rev_fecha',
        'elapsed_from_fields' => ['cita_fecha_inicio', 'ticket_cct_created'],
        'elapsed_from_label'  => 'Cita agendada',
        'link_url'            => 'https://sucasainmobiliaria.com.co/revision-de-recibo/?numero={id_revision_recibo}',
        'empty_text'          => 'Sin revisión antes de la desocupación',
      ],
      [
        'key'                 => 'inmueble_recibido',
        'label'               => 'Inmueble recibido',
        'icon'                => '04',
        'ts_field'            => 'recibo_acta_fecha',
        'done_if'             => 'field_not_empty',
        'done_field'          => 'recibo_acta_fecha',
        'elapsed_from_fields' => ['recibo_rev_fecha'],
        'elapsed_from_label'  => 'Revisión antes de la desocupación',
        'link_url'            => 'https://sucasainmobiliaria.com.co/acta-de-recibo/?numero={id_acta_recibo}',
        'empty_text'          => 'Inmueble sin recibir',
      ],
    ];
  }
}
