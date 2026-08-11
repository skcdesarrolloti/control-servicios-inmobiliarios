<?php

namespace SCM\Support;

final class MaintenanceTimelineMap
{
  /**
   * Timeline principal de mantenimiento.
   *
   * Configuracion pensada para lectura humana:
   * - table: tabla real de donde nace la fecha.
   * - date_columns: columnas reales evaluadas en orden.
   * - status_columns: columnas reales para mostrar el subtitulo/estado.
   * - requires_flag_columns: columnas reales que deben tener Si/true/1/etc.
   * - internal_date_fields/internal_status_fields: campos ya calculados por el repositorio.
   * - internal_flag_fields: campos ya calculados para banderas como "se envio" o "tuvo danos".
   *
   * Para agregar una etapa, copia un bloque y cambia key, label, table y date_columns.
   *
   * @return array<int,array<string,mixed>>
   */
  public static function steps(): array
  {
    return [
      [
        'key' => 'queja_registrada',
        'label' => 'Queja registrada',
        'icon' => 'Q',
        'color' => 'emerald',
        'table' => 'wp_jet_cct_tickets',
        'date_columns' => ['cct_created', 'fecha'],
        'status' => 'always',
        'empty_text' => 'Sin fecha de queja',
      ],
      [
        'key' => 'cita_agendada',
        'label' => 'Cita agendada',
        'icon' => 'C',
        'color' => 'sky',
        'table' => 'calendario_actividades',
        'date_columns' => ['fecha_inicio'],
        'internal_date_fields' => ['_scm_cita_cal_fecha'],
        'empty_text' => 'Sin cita agendada',
      ],
      [
        'key' => 'revision_correctiva_registrada',
        'label' => 'Revision correctiva registrada',
        'icon' => 'R',
        'color' => 'amber',
        'table' => 'wp_jet_cct_revision_correctiva',
        'date_columns' => ['cct_created', 'fecha'],
        'internal_date_fields' => ['_scm_corr_fecha'],
        'status_columns' => ['estado_rev_correctiva'],
        'empty_text' => 'Sin revision correctiva',
      ],
      [
        'key' => 'cotizacion_registrada',
        'label' => 'Cotizacion registrada',
        'icon' => '$',
        'color' => 'violet',
        'table' => 'wp_jet_cct_cotizacion_mantenimiento',
        'date_columns' => ['cct_created', 'fecha'],
        'internal_date_fields' => ['_scm_cot_fecha_registro', 'fecha_cotizacion_mantenimiento'],
        'status_columns' => ['estado_cotizacion_mantenimiento'],
        'visible_when' => 'has_cotizacion',
        'empty_text' => 'Sin cotizacion registrada',
      ],
      [
        'key' => 'cotizacion_enviada',
        'label' => 'Cotizacion enviada',
        'icon' => 'S',
        'color' => 'teal',
        'table' => 'wp_jet_cct_cotizacion_mantenimiento',
        'date_columns' => ['fecha_envio'],
        'requires_flag_columns' => ['se_envio'],
        'requires_flag_values' => ['Si'],
        'internal_flag_fields' => ['fue_enviada_cotizacion_mantenimiento'],
        'internal_date_fields' => ['_scm_cot_fecha_envio', 'fecha_envio_cotizacion_mantenimiento'],
        'visible_when' => 'has_cotizacion',
        'empty_text' => 'Sin envio de cotizacion',
      ],
      [
        'key' => 'respuesta_cotizacion',
        'label' => 'Respuesta cotizacion',
        'icon' => 'E',
        'color' => 'indigo',
        'table' => 'wp_jet_cct_cotizacion_mantenimiento',
        'date_columns' => ['fecha_respuesta'],
        'internal_date_fields' => ['_scm_cot_fecha_respuesta', 'fecha_respuesta_cotizacion_mantenimiento'],
        'status_columns' => ['estado_respuesta_cotizacion_mantenimiento'],
        'visible_when' => 'has_cotizacion',
        'empty_text' => 'Sin respuesta de cotizacion',
      ],
      [
        'key' => 'trabajo_finalizado',
        'label' => 'Trabajo finalizado',
        'icon' => 'F',
        'color' => 'green',
        'table' => 'wp_jet_cct_actas_de_satisfaccion',
        'date_columns' => ['cct_created', 'fecha'],
        'internal_date_fields' => ['_scm_acta_fecha', '_scm_cot_final_trabajo', 'final_trabajo'],
        'visible_when' => 'has_acta_or_cotizacion',
        'empty_text' => 'Sin fecha de trabajo finalizado',
      ],
    ];
  }
}
