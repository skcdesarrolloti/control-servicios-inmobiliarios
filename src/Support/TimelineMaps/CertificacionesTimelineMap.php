<?php

namespace SCM\Support\TimelineMaps;

final class CertificacionesTimelineMap
{
  /**
   * @return array<int,array<string,mixed>>
   */
  public static function steps(): array
  {
    return [
      ['key' => 'creado', 'label' => 'Queja registrada', 'icon' => '01', 'ts_field' => 'cct_created', 'done_if' => 'always'],
      ['key' => 'en_proceso', 'label' => 'En proceso', 'icon' => '02', 'ts_field' => 'fecha_actualizacion', 'done_if' => 'field_in', 'done_field' => 'estado', 'done_values' => ['En proceso', 'Cerrado']],
      ['key' => 'seguimiento', 'label' => 'Seguimiento', 'icon' => '03', 'ts_field' => 'fecha_seguimiento', 'done_if' => 'field_equals', 'done_field' => 'tuvo_seguimiento', 'done_value' => 'Si'],
      ['key' => 'cerrado', 'label' => 'Cerrado', 'icon' => 'OK', 'ts_field' => 'fecha_actualizacion', 'done_if' => 'field_equals', 'done_field' => 'estado', 'done_value' => 'Cerrado'],
    ];
  }
}
