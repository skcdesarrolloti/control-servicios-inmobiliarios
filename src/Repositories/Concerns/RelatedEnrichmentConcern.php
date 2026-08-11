<?php

declare(strict_types=1);

namespace SCM\Repositories\Concerns;

trait RelatedEnrichmentConcern
{
  public function enrichRowsWithRelatedTables(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $allCotIds = [];
    $allPrevIds = [];
    $allCorrIds = [];

    foreach ($rows as $row) {
      foreach ($this->splitIds($row['id_cotizacion_mantenimiento'] ?? '') as $id) {
        $allCotIds[$id] = true;
      }
      foreach ($this->splitIds($row['id_revision_preventiva'] ?? '') as $id) {
        $allPrevIds[$id] = true;
      }
      foreach ($this->splitIds($row['id_revision_correctiva'] ?? '') as $id) {
        $allCorrIds[$id] = true;
      }
    }

    $cotTable  = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $prevTable = $this->db->table('jet_cct_revision_preventiva');
    $corrTable = $this->db->table('jet_cct_revision_correctiva');

    $cotData = $this->fetchRelatedRows(
      $cotTable,
      array_keys($allCotIds),
      ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id'],
      [
        '_ID',
        'id_cotizacion_mantenimiento',
        'id_cotizacion',
        'estado',
        'estado_cotizacion_mantenimiento',
        'estado_respuesta_cotizacion_mantenimiento',
        'estado_respuesta',
        'respuesta',
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
        'perturbacion',
        'justificacion_perturbacion',
        'valor_bonificacion',
        'area_afectada',
        'resumen_calculo_perturbacion',
      ]
    );

    $prevData = $this->fetchRelatedRows(
      $prevTable,
      array_keys($allPrevIds),
      ['_ID', 'id_revision_preventiva', 'id_revision', 'id'],
      [
        '_ID',
        'id_revision_preventiva',
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
        'se_envio',
      ]
    );

    $corrData = $this->fetchRelatedRows(
      $corrTable,
      array_keys($allCorrIds),
      ['_ID', 'id_revision_correctiva', 'id_revision', 'id'],
      [
        '_ID',
        'id_revision_correctiva',
        'estado',
        'estado_rev_correctiva',
        'estado_revision_correctiva',
        'fecha',
        'fecha_revision_correctiva',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
      ]
    );

    foreach ($rows as &$row) {
      $row['_scm_cot_fecha_registro'] = 0;
      $row['_scm_cot_fecha_envio'] = 0;
      $row['_scm_cot_fecha_respuesta'] = 0;
      $row['_scm_prev_fecha'] = 0;
      $row['_scm_prev_encontro_danos'] = '';
      $row['_scm_prev_se_envio'] = '';
      $row['_scm_corr_fecha'] = 0;
      $row['_scm_cot_inicio_trabajo'] = 0;
      $row['_scm_cot_final_trabajo'] = 0;
      $row['_scm_cot_id_acta'] = '';
      $row['_scm_cot_perturbacion'] = '';
      $row['_scm_cot_justificacion_perturbacion'] = '';
      $row['_scm_cot_valor_bonificacion'] = '';
      $row['_scm_cot_area_afectada'] = '';
      $row['_scm_cot_resumen_calculo_perturbacion'] = '';
      $row['_scm_cot_estado'] = '';
      $row['_scm_cita_cal_fecha'] = 0;
      $row['_scm_acta_fecha'] = 0;

      $cotRow = null;
      foreach ($this->splitIds($row['id_cotizacion_mantenimiento'] ?? '') as $id) {
        if (isset($cotData['rows'][$id])) {
          $cotRow = $cotData['rows'][$id];
          break;
        }
      }

      if (is_array($cotRow)) {
        $cotEstado = $this->firstExistingValue($cotRow, ['estado_cotizacion_mantenimiento', 'estado']);
        $row['_scm_cot_estado'] = $cotEstado;
        if ($cotEstado !== '' && trim((string) ($row['estado_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_cotizacion_mantenimiento'] = $cotEstado;
        }

        $cotResp = $this->firstExistingValue($cotRow, ['estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta', 'respuesta']);
        if ($cotResp !== '' && trim((string) ($row['estado_respuesta_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_respuesta_cotizacion_mantenimiento'] = $cotResp;
        }

        $cotSentFlag = $this->firstExistingValue($cotRow, ['fue_enviada_cotizacion_mantenimiento', 'fue_enviada', 'se_envio']);
        if ($cotSentFlag !== '' && trim((string) ($row['fue_enviada_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['fue_enviada_cotizacion_mantenimiento'] = $cotSentFlag;
        }

        $regRaw = $this->firstExistingValue($cotRow, ['cct_created', 'created_at', 'fecha', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $sendFlag = strtolower(trim((string) $cotSentFlag));
        $wasSent = in_array($sendFlag, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
        $sendRaw = $wasSent ? $this->firstExistingValue($cotRow, ['fecha_envio_cotizacion_mantenimiento', 'fecha_envio', 'fecha_enviada', 'fecha_envio_correo']) : '';
        $respRaw = $this->firstExistingValue($cotRow, ['fecha_respuesta', 'fecha_actualizacion', 'cct_modified', 'updated_at']);

        $row['_scm_cot_fecha_registro'] = $this->parser->parse($regRaw);
        $row['_scm_cot_fecha_envio'] = $this->parser->parse($sendRaw);
        $row['_scm_cot_fecha_respuesta'] = $this->parser->parse($respRaw);

        if ((int) $row['_scm_cot_fecha_envio'] > 0) {
          $row['fecha_envio_cotizacion_mantenimiento'] = $row['_scm_cot_fecha_envio'];
        }
        if ((int) $row['_scm_cot_fecha_respuesta'] > 0) {
          $row['fecha_respuesta_cotizacion_mantenimiento'] = $row['_scm_cot_fecha_respuesta'];
        }

        $inicioRaw = $this->firstExistingValue($cotRow, ['inicio_trabajo']);
        $finalRaw = $this->firstExistingValue($cotRow, ['final_trabajo']);
        if ($inicioRaw !== '') {
          $row['_scm_cot_inicio_trabajo'] = $this->parser->parse($inicioRaw);
        }
        if ($finalRaw !== '') {
          $row['_scm_cot_final_trabajo'] = $this->parser->parse($finalRaw);
        }

        $row['_scm_cot_id_acta'] = trim((string) ($cotRow['id_acta_satisfaccion'] ?? ''));
        $row['_scm_cot_perturbacion'] = trim((string) ($cotRow['perturbacion'] ?? ''));
        $row['_scm_cot_justificacion_perturbacion'] = trim((string) ($cotRow['justificacion_perturbacion'] ?? ''));
        $row['_scm_cot_valor_bonificacion'] = trim((string) ($cotRow['valor_bonificacion'] ?? ''));
        $row['_scm_cot_area_afectada'] = trim((string) ($cotRow['area_afectada'] ?? ''));
        $row['_scm_cot_resumen_calculo_perturbacion'] = trim((string) ($cotRow['resumen_calculo_perturbacion'] ?? ''));
        if (trim((string) ($row['perturbacion'] ?? '')) === '' && $row['_scm_cot_perturbacion'] !== '') {
          $row['perturbacion'] = $row['_scm_cot_perturbacion'];
        }
        if (trim((string) ($row['justificacion_perturbacion'] ?? '')) === '' && $row['_scm_cot_justificacion_perturbacion'] !== '') {
          $row['justificacion_perturbacion'] = $row['_scm_cot_justificacion_perturbacion'];
        }
        if (trim((string) ($row['area_afectada'] ?? '')) === '' && $row['_scm_cot_area_afectada'] !== '') {
          $row['area_afectada'] = $row['_scm_cot_area_afectada'];
        }
      }

      $prevRow = null;
      foreach ($this->splitIds($row['id_revision_preventiva'] ?? '') as $id) {
        if (isset($prevData['rows'][$id])) {
          $prevRow = $prevData['rows'][$id];
          break;
        }
      }

      if (is_array($prevRow)) {
        $prevEstado = $this->firstExistingValue($prevRow, ['estado_rev_preventiva', 'estado_revision_preventiva', 'estado']);
        if ($prevEstado !== '' && trim((string) ($row['estado_rev_preventiva'] ?? '')) === '') {
          $row['estado_rev_preventiva'] = $prevEstado;
        }

        $prevDateRaw = $this->firstExistingValue($prevRow, ['fecha_revision_preventiva', 'fecha', 'cct_created', 'created_at', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $row['_scm_prev_fecha'] = $this->parser->parse($prevDateRaw);
        $row['_scm_prev_encontro_danos'] = trim((string) ($prevRow['encontro_danos'] ?? ''));
        $row['_scm_prev_se_envio'] = trim((string) ($prevRow['se_envio'] ?? ''));
        if (trim((string) ($row['se_encontraron_danos'] ?? '')) === '' && $row['_scm_prev_encontro_danos'] !== '') {
          $row['se_encontraron_danos'] = $row['_scm_prev_encontro_danos'];
        }
      }

      $corrRow = null;
      foreach ($this->splitIds($row['id_revision_correctiva'] ?? '') as $id) {
        if (isset($corrData['rows'][$id])) {
          $corrRow = $corrData['rows'][$id];
          break;
        }
      }

      if (is_array($corrRow)) {
        $corrEstado = $this->firstExistingValue($corrRow, ['estado_rev_correctiva', 'estado_revision_correctiva', 'estado']);
        if ($corrEstado !== '' && trim((string) ($row['estado_rev_correctiva'] ?? '')) === '') {
          $row['estado_rev_correctiva'] = $corrEstado;
        }

        $corrDateRaw = $this->firstExistingValue($corrRow, ['cct_created', 'created_at', 'fecha_revision_correctiva', 'fecha', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $row['_scm_corr_fecha'] = $this->parser->parse($corrDateRaw);
      }
    }
    unset($row);

    $allTicketPks = [];
    foreach ($rows as $row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0) {
        $allTicketPks[$ticketPk] = true;
      }
    }

    $calendarMap = [];
    $calendarTable = 'calendario_actividades';
    if (!empty($allTicketPks) && $this->schema->tableExists($calendarTable)) {
      $ticketIds = array_keys($allTicketPks);
      $ph    = implode(',', array_fill(0, count($ticketIds), '?'));
      $sql   = "SELECT `id_ticket`, MIN(`fecha_inicio`) AS `fecha_inicio` FROM `{$calendarTable}` WHERE `id_ticket` IN ({$ph}) GROUP BY `id_ticket`";
      $items = $this->db->getResults($sql, $ticketIds);
      foreach ($items as $item) {
        $ticketPk = (int) ($item['id_ticket'] ?? 0);
        if ($ticketPk > 0) {
          $calendarMap[$ticketPk] = $item['fecha_inicio'] ?? '';
        }
      }
    }

    $allActaIds = [];
    foreach ($rows as $row) {
      $actaId = trim((string) ($row['_scm_cot_id_acta'] ?? ''));
      if ($actaId !== '') {
        $allActaIds[$actaId] = true;
      }
    }

    $actaTable = $this->db->table('jet_cct_actas_de_satisfaccion');
    $actaData = $this->fetchRelatedRows(
      $actaTable,
      array_keys($allActaIds),
      ['_ID', 'id_acta_satisfaccion'],
      ['_ID', 'fecha', 'fecha_satisfaccion', 'cct_created', 'created_at']
    );
    $actaByTicket = $this->fetchRowsGroupedByColumnCandidates(
      $actaTable,
      ['id_ticket'],
      array_map('strval', array_keys($allTicketPks)),
      ['_ID', 'id_ticket', 'fecha', 'fecha_satisfaccion', 'cct_created', 'created_at']
    );

    foreach ($rows as &$row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0 && isset($calendarMap[$ticketPk])) {
        $row['_scm_cita_cal_fecha'] = $this->parser->parse($calendarMap[$ticketPk]);
      }

      $actaId = trim((string) ($row['_scm_cot_id_acta'] ?? ''));
      if ($actaId !== '' && isset($actaData['rows'][$actaId])) {
        $actaRow = $actaData['rows'][$actaId];
        $actaDateRaw = $this->firstExistingValue($actaRow, ['fecha_satisfaccion', 'fecha', 'cct_created', 'created_at']);
        $row['_scm_acta_fecha'] = $this->parser->parse($actaDateRaw);
      }

      if ($ticketPk > 0 && !empty($actaByTicket[(string) $ticketPk])) {
        $bestTs = (int) ($row['_scm_acta_fecha'] ?? 0);
        foreach ((array) $actaByTicket[(string) $ticketPk] as $actaTicketRow) {
          if (!is_array($actaTicketRow)) {
            continue;
          }
          $candidateRaw = $this->firstExistingValue($actaTicketRow, ['cct_created', 'fecha_satisfaccion', 'fecha', 'created_at']);
          $candidateTs = $this->parser->parse($candidateRaw);
          if ($candidateTs > $bestTs) {
            $bestTs = $candidateTs;
          }
        }
        if ($bestTs > 0) {
          $row['_scm_acta_fecha'] = $bestTs;
        }
      }
    }
    unset($row);

    $rows = $this->applyTimelineEnrichmentByTicket($rows);
    $rows = $this->enrichRowsWithHistorial($rows);
    $rows = $this->enrichRowsWithCaseDetails($rows);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
}
