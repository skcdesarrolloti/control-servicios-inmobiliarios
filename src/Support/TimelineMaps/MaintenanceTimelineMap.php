<?php

namespace SCM\Support\TimelineMaps;

final class MaintenanceTimelineMap
{
  /**
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
        'join_on' => 'wp_jet_cct_tickets._ID = ticket._ID',
        'date_columns' => ['cct_created'],
        'status' => 'always',
        'empty_text' => 'Sin fecha de queja',
      ],
      [
        'key' => 'cita_agendada',
        'label' => 'Cita agendada',
        'icon' => 'C',
        'color' => 'sky',
        'table' => 'calendario_actividades',
        'join_on' => 'calendario_actividades.id_ticket = wp_jet_cct_tickets._ID',
        'date_columns' => ['fecha_inicio'],
        'internal_date_fields' => ['_scm_cita_cal_fecha'],
        'elapsed_from_fields' => ['cct_created', 'fecha'],
        'elapsed_from_label' => 'Queja registrada',
        'empty_text' => 'Cita sin agendar',
      ],
      [
        'key' => 'revision_correctiva_registrada',
        'label' => 'Revision correctiva registrada',
        'icon' => 'R',
        'color' => 'amber',
        'table' => 'wp_jet_cct_revision_correctiva',
        'join_on' => 'wp_jet_cct_revision_correctiva.id_ticket = wp_jet_cct_tickets._ID',
        'date_columns' => ['cct_created'],
        'internal_date_fields' => ['_scm_corr_fecha'],
        'status_columns' => ['estado_rev_correctiva'],
        'elapsed_from_fields' => ['_scm_cita_cal_fecha', 'cct_created', 'fecha'],
        'elapsed_from_label' => 'Cita agendada',
        'empty_text' => 'Sin revisión correctiva',
      ],
      [
        'key' => 'cotizacion_registrada',
        'label' => 'Cotizacion registrada',
        'icon' => '$',
        'color' => 'violet',
        'table' => 'wp_jet_cct_cotizacion_mantenimiento',
        'join_on' => 'wp_jet_cct_cotizacion_mantenimiento.id_ticket = wp_jet_cct_tickets._ID',
        'date_columns' => ['cct_created'],
        'internal_date_fields' => ['_scm_cot_fecha_registro'],
        'elapsed_from_fields' => ['_scm_corr_fecha'],
        'elapsed_from_label' => 'Revision correctiva registrada',
        'visible_when' => 'has_cotizacion',
        'empty_text' => 'Sin cotización registrada',
      ],
      [
        'key' => 'cotizacion_enviada',
        'label' => 'Cotizacion enviada',
        'icon' => 'S',
        'color' => 'teal',
        'table' => 'wp_jet_cct_cotizacion_mantenimiento',
        'join_on' => 'wp_jet_cct_cotizacion_mantenimiento.id_ticket = wp_jet_cct_tickets._ID',
        'date_columns' => ['fecha_envio'],
        'requires_flag_columns' => ['se_envio'],
        'requires_flag_values' => ['Si'],
        'internal_date_fields' => ['_scm_cot_fecha_envio'],
        'elapsed_from_fields' => ['_scm_cot_fecha_registro'],
        'elapsed_from_label' => 'Cotizacion registrada',
        'visible_when' => 'has_cotizacion',
        'empty_text' => 'Sin envio de cotizacion',
      ],
      [
        'key' => 'respuesta_cotizacion',
        'label' => 'Respuesta cotizacion',
        'icon' => 'E',
        'color' => 'indigo',
        'table' => 'wp_jet_cct_cotizacion_mantenimiento',
        'join_on' => 'wp_jet_cct_cotizacion_mantenimiento.id_ticket = wp_jet_cct_tickets._ID',
        'date_columns' => ['fecha_respuesta'],
        'internal_date_fields' => ['_scm_cot_fecha_respuesta'],
        'status_columns' => ['estado'],
        'elapsed_from_fields' => ['_scm_cot_fecha_envio'],
        'elapsed_from_label' => 'Cotizacion enviada',
        'visible_when' => 'has_cotizacion',
        'empty_text' => 'Sin respuesta de cotizacion',
      ],
      [
        'key' => 'trabajo_finalizado',
        'label' => 'Trabajo finalizado',
        'icon' => 'F',
        'color' => 'green',
        'table' => 'wp_jet_cct_actas_de_satisfaccion',
        'join_on' => 'wp_jet_cct_actas_de_satisfaccion.id_ticket = wp_jet_cct_tickets._ID',
        'date_columns' => ['cct_created'],
        'internal_date_fields' => ['_scm_acta_fecha'],
        'elapsed_from_fields' => ['_scm_cot_fecha_respuesta'],
        'elapsed_from_label' => 'Respuesta cotizacion',
        'visible_when' => 'has_acta_cotizacion_aprobada',
        'empty_text' => 'Sin fecha de trabajo finalizado',
      ],
    ];
  }
}

