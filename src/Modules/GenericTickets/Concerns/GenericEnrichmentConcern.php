<?php

declare(strict_types=1);

namespace SCM\Modules\GenericTickets\Concerns;

use SCM\Core\Auth;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimelineMaps\CertificacionesTimelineMap;
use SCM\Support\TimelineMaps\ContableTimelineMap;
use SCM\Support\TimelineMaps\ContractualTimelineMap;
use SCM\Support\TimelineMaps\EntregaTimelineMap;
use SCM\Support\TimelineMaps\PreventivaTimelineMap;
use SCM\Support\TimelineMaps\ReciboTimelineMap;

trait GenericEnrichmentConcern
{
  private function enrich_generic_case_details(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $ticketKeys = [];
    $contractIds = [];
    $propertyIds = [];
    $ownerIds = [];
    $tenantIds = [];
    foreach ($rows as $row) {
      foreach ($this->resolve_ticket_lookup_keys($row) as $key) {
        $ticketKeys[$key] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['contrato', 'id_contrato'])) as $id) {
        $contractIds[$id] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['id_inmueble'])) as $id) {
        $propertyIds[$id] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['id_propietario'])) as $id) {
        $ownerIds[$id] = true;
      }
      foreach ($this->split_id_values($this->first_existing_value($row, ['id_arrendatario'])) as $id) {
        $tenantIds[$id] = true;
      }
    }

    $segByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_seguimiento_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified', 'id_coordinador', 'id_empleado', 'evidencia']
    );
    $notesByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_notas_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'id_empleado', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified']
    );
    $contractById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_contratos_arrendamiento'),
      ['_ID', 'contrato'],
      array_keys($contractIds)
    );
    $propertyById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_inmuebles'),
      ['codigo'],
      array_keys($propertyIds)
    );
    $ownerById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_propietarios'),
      ['id_propietario'],
      array_keys($ownerIds)
    );
    $tenantById = $this->fetch_single_rows_by_id_candidates(
      $this->db->table('jet_cct_arrendatarios'),
      ['id_arrendatario'],
      array_keys($tenantIds)
    );
    $histByProperty = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_inmueble', 'id_inmueble_data'],
      array_keys($propertyIds),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id']
    );
    $histByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id']
    );

    foreach ($rows as &$row) {
      $row['_scm_seguimientos_ticket'] = [];
      $row['_scm_notas_ticket'] = [];
      $row['_scm_historial_inmueble'] = [];
      $row['_scm_contrato_data'] = [];
      $row['_scm_inmueble_data'] = [];
      $row['_scm_propietario_data'] = [];
      $row['_scm_arrendatario_data'] = [];

      foreach ($this->resolve_ticket_lookup_keys($row) as $key) {
        if (!empty($segByTicket[$key])) {
          $row['_scm_seguimientos_ticket'] = array_merge($row['_scm_seguimientos_ticket'], $segByTicket[$key]);
        }
        if (!empty($notesByTicket[$key])) {
          $row['_scm_notas_ticket'] = array_merge($row['_scm_notas_ticket'], $notesByTicket[$key]);
        }
      }

      $contractId = $this->first_id_value($this->first_existing_value($row, ['contrato', 'id_contrato']));
      if ($contractId !== '' && isset($contractById[$contractId])) {
        $row['_scm_contrato_data'] = $contractById[$contractId];
      }

      $propertyId = $this->first_id_value($this->first_existing_value($row, ['id_inmueble']));
      if ($propertyId === '' && !empty($row['_scm_contrato_data']) && is_array($row['_scm_contrato_data'])) {
        $propertyId = $this->first_id_value($this->first_existing_value($row['_scm_contrato_data'], ['id_inmueble']));
      }
      if ($propertyId !== '' && isset($propertyById[$propertyId])) {
        $row['_scm_inmueble_data'] = $propertyById[$propertyId];
      }
      $ownerId = $this->first_id_value($this->first_existing_value($row, ['id_propietario']));
      if ($ownerId !== '' && isset($ownerById[$ownerId])) {
        $row['_scm_propietario_data'] = $ownerById[$ownerId];
        if (trim((string) ($row['indicativo_propietario'] ?? '')) === '') {
          $row['indicativo_propietario'] = trim((string) ($ownerById[$ownerId]['indicativo'] ?? ''));
        }
      }
      $tenantId = $this->first_id_value($this->first_existing_value($row, ['id_arrendatario']));
      if ($tenantId !== '' && isset($tenantById[$tenantId])) {
        $row['_scm_arrendatario_data'] = $tenantById[$tenantId];
        if (trim((string) ($row['indicativo_arrendatario'] ?? '')) === '') {
          $row['indicativo_arrendatario'] = trim((string) ($tenantById[$tenantId]['indicativo'] ?? ''));
        }
      }
      if ($propertyId !== '' && isset($histByProperty[$propertyId])) {
        $row['_scm_historial_inmueble'] = $histByProperty[$propertyId];
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $key) {
        if (!empty($histByTicket[$key])) {
          $row['_scm_historial_inmueble'] = array_merge($row['_scm_historial_inmueble'], $histByTicket[$key]);
        }
      }
      if (!empty($row['_scm_historial_inmueble'])) {
        $uniqueHist = [];
        foreach ($row['_scm_historial_inmueble'] as $histItem) {
          if (!is_array($histItem)) {
            continue;
          }
          $histKey = trim((string)($histItem['_ID'] ?? ''));
          if ($histKey === '') {
            $histKey = md5(json_encode($histItem));
          }
          $uniqueHist[$histKey] = $histItem;
        }
        $row['_scm_historial_inmueble'] = array_values($uniqueHist);
      }

      $this->sort_rows_by_date_desc($row['_scm_seguimientos_ticket']);
      $this->sort_rows_by_date_desc($row['_scm_notas_ticket']);
      $this->sort_rows_by_date_desc($row['_scm_historial_inmueble']);
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrich_ticket_rows_with_related_data(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $prevIds = [];
    $cotIds = [];
    $calendarIds = [];
    $ticketKeys = [];
    foreach ($rows as $row) {
      foreach ($this->split_id_values($row['id_revision_preventiva'] ?? '') as $id) {
        $prevIds[$id] = true;
      }
      foreach ($this->split_id_values($row['id_cotizacion_mantenimiento'] ?? $row['id_cotizacion'] ?? '') as $id) {
        $cotIds[$id] = true;
      }
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0) {
        $calendarIds[$ticketPk] = true;
      }
      $logicalTicket = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalTicket !== '') {
        $ticketKeys[$logicalTicket] = true;
      }
      if ($logicalTicket !== '' && ctype_digit($logicalTicket)) {
        $calendarIds[(int) $logicalTicket] = true;
      }
    }

    $prevRows = $this->fetch_related_rows_by_ids(
      $this->db->table('jet_cct_revision_preventiva'),
      array_keys($prevIds),
      ['_ID', 'id_revision_preventiva', 'id_revision', 'id'],
      [
        '_ID',
        'id_revision_preventiva',
        'id_revision',
        'id',
        'estado',
        'estado_rev_preventiva',
        'estado_revision_preventiva',
        'fecha',
        'fecha_revision_preventiva',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'encontro_danos',
        'tiene_cotizacion',
        'se_envio',
      ]
    );

    $cotRows = $this->fetch_related_rows_by_ids(
      $this->db->table('jet_cct_cotizacion_mantenimiento'),
      array_keys($cotIds),
      ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id'],
      [
        '_ID',
        'id_cotizacion_mantenimiento',
        'id_cotizacion',
        'id',
        'id_ticket',
        'estado',
        'estado_cotizacion_mantenimiento',
        'estado_respuesta_cotizacion_mantenimiento',
        'estado_respuesta',
        'respuesta',
        'perturbacion',
        'justificacion_perturbacion',
        'valor_bonificacion',
        'area_afectada',
        'fecha',
        'fecha_envio',
        'fecha_respuesta',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'inicio_trabajo',
        'final_trabajo',
        'id_acta_satisfaccion',
      ]
    );

    $calendarRows = $this->fetch_calendar_dates(array_keys($calendarIds));
    $cotRowsByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_cotizacion_mantenimiento'),
      ['id_ticket'],
      array_keys($ticketKeys),
      [
        '_ID',
        'id_ticket',
        'perturbacion',
        'justificacion_perturbacion',
        'valor_bonificacion',
        'area_afectada',
      ]
    );

    $entregaRevByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_revision_entrega'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $inventarioByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_inventario'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $actaEntregaByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_actas_de_entrega'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $reciboRevByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_revision_recibo'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );
    $actaReciboByTicket = $this->fetch_rows_grouped_by_column_candidates(
      $this->db->table('jet_cct_actas_de_recibo'),
      ['id_ticket'],
      array_keys($calendarIds),
      ['_ID', 'id_ticket', 'cct_created']
    );

    foreach ($rows as &$row) {
      $row['_scm_cita_cal_fecha'] = $row['_scm_cita_cal_fecha'] ?? '';
      $row['_scm_prev_fecha'] = $row['_scm_prev_fecha'] ?? '';
      $row['_scm_prev_encontro_danos'] = $row['_scm_prev_encontro_danos'] ?? '';
      $row['_scm_prev_se_envio'] = $row['_scm_prev_se_envio'] ?? '';
      $row['_scm_cot_fecha_registro'] = $row['_scm_cot_fecha_registro'] ?? '';
      $row['_scm_cot_fecha_envio'] = $row['_scm_cot_fecha_envio'] ?? '';
      $row['_scm_cot_fecha_respuesta'] = $row['_scm_cot_fecha_respuesta'] ?? '';
      $row['_scm_cot_final_trabajo'] = $row['_scm_cot_final_trabajo'] ?? '';
      $row['_scm_cot_id_acta'] = $row['_scm_cot_id_acta'] ?? '';
      $row['_scm_cot_perturbacion'] = $row['_scm_cot_perturbacion'] ?? '';
      $row['_scm_cot_justificacion_perturbacion'] = $row['_scm_cot_justificacion_perturbacion'] ?? '';
      $row['_scm_cot_valor_bonificacion'] = $row['_scm_cot_valor_bonificacion'] ?? '';
      $row['_scm_cot_area_afectada'] = $row['_scm_cot_area_afectada'] ?? '';
      $row['cita_fecha_inicio'] = $row['cita_fecha_inicio'] ?? '';
      $row['prev_fecha_registro'] = $row['prev_fecha_registro'] ?? '';
      $row['prev_se_envio'] = $row['prev_se_envio'] ?? '';
      $row['cot_id'] = $row['cot_id'] ?? '';
      $row['cot_fecha_registro'] = $row['cot_fecha_registro'] ?? '';
      $row['cot_fecha_envio'] = $row['cot_fecha_envio'] ?? '';
      $row['cot_fecha_respuesta'] = $row['cot_fecha_respuesta'] ?? '';
      $row['cot_final_trabajo'] = $row['cot_final_trabajo'] ?? '';
      $row['ticket_cct_created'] = $row['ticket_cct_created'] ?? '';
      $row['id_cita_agendada'] = $row['id_cita_agendada'] ?? '';
      $row['cita_evento_id'] = $row['cita_evento_id'] ?? '';
      $row['entrega_rev_fecha'] = $row['entrega_rev_fecha'] ?? '';
      $row['id_revision_entrega'] = $row['id_revision_entrega'] ?? '';
      $row['entrega_inventario_fecha'] = $row['entrega_inventario_fecha'] ?? '';
      $row['id_inventario_entrega'] = $row['id_inventario_entrega'] ?? '';
      $row['entrega_acta_fecha'] = $row['entrega_acta_fecha'] ?? '';
      $row['id_acta_entrega'] = $row['id_acta_entrega'] ?? '';
      $row['recibo_rev_fecha'] = $row['recibo_rev_fecha'] ?? '';
      $row['id_revision_recibo'] = $row['id_revision_recibo'] ?? '';
      $row['recibo_acta_fecha'] = $row['recibo_acta_fecha'] ?? '';
      $row['id_acta_recibo'] = $row['id_acta_recibo'] ?? '';

      $ticketPk = (int) ($row['_ID'] ?? 0);
      $logicalTicket = trim((string) ($row['id_ticket'] ?? ''));

      if (trim((string) ($row['ticket_cct_created'] ?? '')) === '') {
        $ticketCreatedTs = $this->parse_unix_ts((string) ($row['cct_created'] ?? ''));
        if ($ticketCreatedTs > 0) {
          $row['ticket_cct_created'] = (string) $ticketCreatedTs;
        }
      }

      foreach ([$ticketPk, ctype_digit($logicalTicket) ? (int) $logicalTicket : 0] as $calendarId) {
        if ($calendarId > 0 && isset($calendarRows[$calendarId])) {
          $calendarEvent = $calendarRows[$calendarId];
          $calendarTs = $this->parse_unix_ts($calendarEvent['fecha_inicio']);
          $row['_scm_cita_cal_fecha'] = $calendarTs > 0 ? (string) $calendarTs : '';
          $row['cita_fecha_inicio'] = $row['_scm_cita_cal_fecha'];
          $row['id_cita_agendada'] = $calendarEvent['id'];
          $row['cita_evento_id'] = $calendarEvent['id'];
          break;
        }
      }

      $prevRow = $this->first_related_row($prevRows, $row['id_revision_preventiva'] ?? '');
      if (is_array($prevRow)) {
        $prevDate = $this->first_existing_value($prevRow, ['fecha_revision_preventiva', 'fecha', 'cct_created', 'created_at', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $prevTs = $this->parse_unix_ts($prevDate);
        $row['_scm_prev_fecha'] = $prevTs > 0 ? (string) $prevTs : '';
        $row['_scm_prev_encontro_danos'] = $this->first_existing_value($prevRow, ['encontro_danos', 'tiene_cotizacion']);
        $row['_scm_prev_se_envio'] = $this->first_existing_value($prevRow, ['se_envio']);
        $row['prev_fecha_registro'] = $row['_scm_prev_fecha'];
        $row['prev_se_envio'] = $row['_scm_prev_se_envio'];

        $prevEstado = $this->first_existing_value($prevRow, ['estado_rev_preventiva', 'estado_revision_preventiva', 'estado']);
        if ($prevEstado !== '' && trim((string) ($row['estado_rev_preventiva'] ?? '')) === '') {
          $row['estado_rev_preventiva'] = $prevEstado;
        }
        if (trim((string) ($row['se_encontraron_danos'] ?? '')) === '' && $row['_scm_prev_encontro_danos'] !== '') {
          $row['se_encontraron_danos'] = $row['_scm_prev_encontro_danos'];
        }
      }

      $cotRow = $this->first_related_row($cotRows, $row['id_cotizacion_mantenimiento'] ?? $row['id_cotizacion'] ?? '');
      if (!is_array($cotRow)) {
        foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
          if (!empty($cotRowsByTicket[$tk]) && is_array($cotRowsByTicket[$tk][0] ?? null)) {
            $chosen = $cotRowsByTicket[$tk][0];
            foreach ($cotRowsByTicket[$tk] as $candidate) {
              if (!is_array($candidate)) {
                continue;
              }
              $pVal = strtolower(trim((string) ($candidate['perturbacion'] ?? '')));
              if ($pVal !== '' && $pVal !== '0' && $pVal !== 'no' && $pVal !== 'false' && $pVal !== 'null') {
                $chosen = $candidate;
                break;
              }
            }
            $cotRow = $chosen;
            break;
          }
        }
      }
      if (is_array($cotRow)) {
        $cotReg = $this->first_existing_value($cotRow, ['cct_created', 'created_at', 'fecha', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $cotSentFlag = $this->first_existing_value($cotRow, ['fue_enviada_cotizacion_mantenimiento', 'fue_enviada', 'se_envio']);
        $cotSentOk = in_array(strtolower(trim((string) $cotSentFlag)), ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
        $cotSend = $cotSentOk ? $this->first_existing_value($cotRow, ['fecha_envio_cotizacion_mantenimiento', 'fecha_envio', 'fecha_enviada', 'fecha_envio_correo']) : '';
        $cotResp = $this->first_existing_value($cotRow, ['fecha_respuesta', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $cotFinal = $this->first_existing_value($cotRow, ['final_trabajo']);

        $cotRegTs = $this->parse_unix_ts($cotReg);
        $cotSendTs = $this->parse_unix_ts($cotSend);
        $cotRespTs = $this->parse_unix_ts($cotResp);
        $cotFinalTs = $this->parse_unix_ts($cotFinal);
        $row['_scm_cot_fecha_registro'] = $cotRegTs > 0 ? (string) $cotRegTs : '';
        $row['_scm_cot_fecha_envio'] = $cotSendTs > 0 ? (string) $cotSendTs : '';
        $row['_scm_cot_fecha_respuesta'] = $cotRespTs > 0 ? (string) $cotRespTs : '';
        $row['_scm_cot_final_trabajo'] = $cotFinalTs > 0 ? (string) $cotFinalTs : '';
        $row['cot_id'] = trim((string) ($cotRow['_ID'] ?? $cotRow['id_cotizacion_mantenimiento'] ?? $cotRow['id_cotizacion'] ?? ''));
        $row['cot_fecha_registro'] = $row['_scm_cot_fecha_registro'];
        $row['cot_fecha_envio'] = $row['_scm_cot_fecha_envio'];
        $row['cot_fecha_respuesta'] = $row['_scm_cot_fecha_respuesta'];
        $row['cot_final_trabajo'] = $row['_scm_cot_final_trabajo'];
        $row['_scm_cot_id_acta'] = trim((string) ($cotRow['id_acta_satisfaccion'] ?? ''));
        $row['_scm_cot_perturbacion'] = trim((string) ($cotRow['perturbacion'] ?? ''));
        $row['_scm_cot_justificacion_perturbacion'] = trim((string) ($cotRow['justificacion_perturbacion'] ?? ''));
        $row['_scm_cot_valor_bonificacion'] = trim((string) ($cotRow['valor_bonificacion'] ?? ''));
        $row['_scm_cot_area_afectada'] = trim((string) ($cotRow['area_afectada'] ?? ''));
        if (trim((string) ($row['perturbacion'] ?? '')) === '' && $row['_scm_cot_perturbacion'] !== '') {
          $row['perturbacion'] = $row['_scm_cot_perturbacion'];
        }
        if (trim((string) ($row['justificacion_perturbacion'] ?? '')) === '' && $row['_scm_cot_justificacion_perturbacion'] !== '') {
          $row['justificacion_perturbacion'] = $row['_scm_cot_justificacion_perturbacion'];
        }
        if (trim((string) ($row['valor_bonificacion'] ?? '')) === '' && $row['_scm_cot_valor_bonificacion'] !== '') {
          $row['valor_bonificacion'] = $row['_scm_cot_valor_bonificacion'];
        }
        if (trim((string) ($row['area_afectada'] ?? '')) === '' && $row['_scm_cot_area_afectada'] !== '') {
          $row['area_afectada'] = $row['_scm_cot_area_afectada'];
        }
        if ($cotSentFlag !== '' && trim((string) ($row['fue_enviada_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['fue_enviada_cotizacion_mantenimiento'] = $cotSentFlag;
        }

        $cotEstado = $this->first_existing_value($cotRow, ['estado_cotizacion_mantenimiento', 'estado']);
        if ($cotEstado !== '' && trim((string) ($row['estado_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_cotizacion_mantenimiento'] = $cotEstado;
        }
        $cotRespEstado = $this->first_existing_value($cotRow, ['estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta', 'respuesta']);
        $row['_scm_cot_respuesta_estado'] = $cotRespEstado;
        if ($cotRespEstado !== '' && trim((string) ($row['estado_respuesta_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_respuesta_cotizacion_mantenimiento'] = $cotRespEstado;
        }
      }

      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['entrega_rev_fecha'] === '' && !empty($entregaRevByTicket[$tk])) {
          $revRow = $entregaRevByTicket[$tk][0];
          if (is_array($revRow)) {
            $row['id_revision_entrega'] = trim((string) ($revRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($revRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['entrega_rev_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['entrega_inventario_fecha'] === '' && !empty($inventarioByTicket[$tk])) {
          $invRow = $inventarioByTicket[$tk][0];
          if (is_array($invRow)) {
            $row['id_inventario_entrega'] = trim((string) ($invRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($invRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['entrega_inventario_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['entrega_acta_fecha'] === '' && !empty($actaEntregaByTicket[$tk])) {
          $actaRow = $actaEntregaByTicket[$tk][0];
          if (is_array($actaRow)) {
            $row['id_acta_entrega'] = trim((string) ($actaRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($actaRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['entrega_acta_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['recibo_rev_fecha'] === '' && !empty($reciboRevByTicket[$tk])) {
          $revRow = $reciboRevByTicket[$tk][0];
          if (is_array($revRow)) {
            $row['id_revision_recibo'] = trim((string) ($revRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($revRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['recibo_rev_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
      foreach ($this->resolve_ticket_lookup_keys($row) as $tk) {
        if ($row['recibo_acta_fecha'] === '' && !empty($actaReciboByTicket[$tk])) {
          $actaRow = $actaReciboByTicket[$tk][0];
          if (is_array($actaRow)) {
            $row['id_acta_recibo'] = trim((string) ($actaRow['_ID'] ?? ''));
            $ts = $this->parse_unix_ts((string) ($actaRow['cct_created'] ?? ''));
            if ($ts > 0) {
              $row['recibo_acta_fecha'] = (string) $ts;
            }
          }
          break;
        }
      }
    }
    unset($row);

    return $rows;
  }

  /** @param array<int,int|string> $ticketIds @return array<int,array<string,string>> */
  private function fetch_calendar_dates(array $ticketIds): array
  {
    $ticketIds = array_values(array_filter(array_unique(array_map('intval', $ticketIds)), static fn(int $id): bool => $id > 0));
    if (empty($ticketIds) || !$this->table_exists('calendario_actividades')) {
      return [];
    }

    $ph = implode(',', array_fill(0, count($ticketIds), '?'));
    $rows = $this->db->getResults(
      "SELECT `id`, `id_ticket`, `fecha_inicio` FROM `calendario_actividades` WHERE `id_ticket` IN ({$ph}) ORDER BY `id` DESC",
      $ticketIds
    );

    $out = [];
    foreach ($rows as $row) {
      $id = (int) ($row['id_ticket'] ?? 0);
      if ($id > 0 && !isset($out[$id])) {
        $out[$id] = [
          'id'          => (string) ($row['id'] ?? ''),
          'fecha_inicio' => (string) ($row['fecha_inicio'] ?? ''),
        ];
      }
    }
    return $out;
  }

  /** @param array<string,mixed> $row @return array<int,string> */
  private function resolve_ticket_lookup_keys(array $row): array
  {
    $keys = [];
    $ticketPk = (int) ($row['_ID'] ?? 0);
    if ($ticketPk > 0) {
      $keys[] = (string) $ticketPk;
    }

    $logicalId = trim((string) ($row['id_ticket'] ?? ''));
    if ($logicalId !== '' && !in_array($logicalId, $keys, true)) {
      $keys[] = $logicalId;
    }

    return $keys;
  }

  /**
   * @param array<int,string> $columnCandidates
   * @param array<int,string> $keys
   * @param array<int,string> $wantedColumns
   * @return array<string,array<int,array<string,mixed>>>
   */
  private function fetch_rows_grouped_by_column_candidates(string $table, array $columnCandidates, array $keys, array $wantedColumns): array
  {
    $keys = array_values(array_filter(array_unique(array_map('strval', $keys)), static fn(string $key): bool => trim($key) !== ''));
    if (empty($keys) || !$this->table_exists($table)) {
      return [];
    }

    $columns = [];
    foreach ($columnCandidates as $column) {
      if ($this->column_exists($table, $column)) {
        $columns[] = $column;
      }
    }
    if (empty($columns)) {
      return [];
    }

    $selectCols = [];
    foreach ($wantedColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $selectCols[] = $column;
      }
    }
    foreach ($columns as $column) {
      if (!in_array($column, $selectCols, true)) {
        $selectCols[] = $column;
      }
    }
    if (empty($selectCols)) {
      return [];
    }

    $ph = implode(',', array_fill(0, count($keys), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $whereParts = [];
    $args = [];
    foreach ($columns as $column) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$column}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($keys as $key) {
        $args[] = $key;
      }
    }

    $select = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $selectCols));
    $rows = $this->db->getResults("SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts), $args);

    $grouped = [];
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        $key = trim((string) ($row[$column] ?? ''));
        if ($key !== '') {
          $grouped[$key][] = $row;
        }
      }
    }
    return $grouped;
  }

  /**
   * @param array<int,string> $idColumns
   * @param array<int,string> $ids
   * @return array<string,array<string,mixed>>
   */
  private function fetch_single_rows_by_id_candidates(string $table, array $idColumns, array $ids): array
  {
    $ids = array_values(array_filter(array_unique(array_map('strval', $ids)), static fn(string $id): bool => trim($id) !== ''));
    if (empty($ids) || !$this->table_exists($table)) {
      return [];
    }

    $columns = [];
    foreach ($idColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $columns[] = $column;
      }
    }
    if (empty($columns)) {
      return [];
    }

    $selectCols = $this->get_existing_columns($table, [
      '_ID',
      'contrato',
      'codigo',
      'codigo_inmueble_web',
      'id_contrato',
      'id_contrato_mandato',
      'id_contrato_arrendamiento',
      'id_inmueble',
      'id_inmueble_data',
      'inmueble',
      'arrendatario',
      'propietario',
      'nombre',
      'correo',
      'celular',
      'indicativo',
      'direccion',
      'barrio',
      'ciudad',
      'tipo_inmueble',
      'tipo_negocio',
      'destinacion',
      'uso',
      'estado',
      'precio_arriendo',
      'precio_venta',
      'precio_admin',
      'area_construida',
      'area_privada',
      'habitaciones',
      'banos',
      'parqueaderos',
      'estrato',
      'copropiedad',
      'matricula_inmobiliaria',
      'valor_canon',
      'valor_administracion',
      'tasa_administracion',
      'porcentaje_incremento_admin',
      'derechos_inmobiliarios',
      'aseguradora',
      'numero_solicitud',
      'id_estudio_aseguradora',
      'id_hoja_cierre',
      'id_cierre',
      'id_inventario',
      'registro_fotografico',
      'id_revision_preventiva',
      'id_revision_correctiva',
      'id_revision_entrega',
      'id_revision_recibo',
      'id_revision_sp',
      'id_revision_servicios_publicos',
      'ubicacion_google_maps',
      'ubicacion_openstreetmap',
      'fecha',
      'cct_created',
      'cct_modified',
    ]);
    foreach ($columns as $column) {
      if (!in_array($column, $selectCols, true)) {
        $selectCols[] = $column;
      }
    }

    $ph = implode(',', array_fill(0, count($ids), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $whereParts = [];
    $args = [];
    foreach ($columns as $column) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$column}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($ids as $id) {
        $args[] = $id;
      }
    }

    $select = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $selectCols));
    $rows = $this->db->getResults("SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts), $args);

    $out = [];
    foreach ($rows as $row) {
      foreach ($columns as $column) {
        $id = trim((string) ($row[$column] ?? ''));
        if ($id !== '') {
          $out[$id] = $row;
        }
      }
    }
    return $out;
  }

  /** @return array<int,string> */
  private function get_existing_columns(string $table, array $columns): array
  {
    $out = [];
    foreach ($columns as $column) {
      if ($this->column_exists($table, $column)) {
        $out[] = $column;
      }
    }
    return $out;
  }

  /** @param array<int,array<string,mixed>> $rows */
  private function sort_rows_by_date_desc(array &$rows): void
  {
    usort($rows, function (array $a, array $b): int {
      return $this->parse_unix_ts($b['fecha'] ?? $b['cct_created'] ?? $b['cct_modified'] ?? '') <=> $this->parse_unix_ts($a['fecha'] ?? $a['cct_created'] ?? $a['cct_modified'] ?? '');
    });
  }

  /** @return array<string,array<string,mixed>> */
  private function fetch_related_rows_by_ids(string $table, array $ids, array $idColumns, array $selectColumns): array
  {
    $ids = array_values(array_filter(array_unique(array_map('strval', $ids)), static fn(string $id): bool => trim($id) !== ''));
    if (empty($ids) || !$this->table_exists($table)) {
      return [];
    }

    $existingIdColumns = [];
    foreach ($idColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $existingIdColumns[] = $column;
      }
    }
    if (empty($existingIdColumns)) {
      return [];
    }

    $existingSelectColumns = [];
    foreach ($selectColumns as $column) {
      if ($this->column_exists($table, $column)) {
        $existingSelectColumns[] = $column;
      }
    }
    foreach ($existingIdColumns as $column) {
      if (!in_array($column, $existingSelectColumns, true)) {
        $existingSelectColumns[] = $column;
      }
    }

    $ph = implode(',', array_fill(0, count($ids), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $whereParts = [];
    $args = [];
    foreach ($existingIdColumns as $column) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$column}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($ids as $id) {
        $args[] = $id;
      }
    }

    $select = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $existingSelectColumns));
    $rows = $this->db->getResults("SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts), $args);

    $map = [];
    foreach ($rows as $row) {
      foreach ($existingIdColumns as $column) {
        $id = trim((string) ($row[$column] ?? ''));
        if ($id !== '') {
          $map[$id] = $row;
        }
      }
    }
    return $map;
  }

  /** @param array<string,array<string,mixed>> $relatedRows */
  private function first_related_row(array $relatedRows, $rawIds): ?array
  {
    foreach ($this->split_id_values($rawIds) as $id) {
      if (isset($relatedRows[$id])) {
        return $relatedRows[$id];
      }
    }
    return null;
  }

  /** @return array<int,string> */
  private function split_id_values($raw): array
  {
    $parts = preg_split('/[,\s;|]+/', trim((string) $raw));
    if (!is_array($parts)) {
      return [];
    }
    $ids = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        $ids[] = $id;
      }
    }
    return array_values(array_unique($ids));
  }

  /** @param array<string,mixed> $row */
  private function first_existing_value(array $row, array $columns): string
  {
    foreach ($columns as $column) {
      $value = trim((string) ($row[$column] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /** @return array<string,mixed> */
}
