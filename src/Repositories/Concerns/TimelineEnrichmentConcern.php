<?php

declare(strict_types=1);

namespace SCM\Repositories\Concerns;

trait TimelineEnrichmentConcern
{
  private function applyTimelineEnrichmentByTicket(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $pkStringKeys = [];
    $logicalStringKeys = [];
    $calendarIntKeys = [];

    foreach ($rows as $row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0) {
        $pkStringKeys[(string) $ticketPk] = true;
        $calendarIntKeys[$ticketPk] = true;
      }

      $logicalId = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalId !== '') {
        $logicalStringKeys[$logicalId] = true;
        if (ctype_digit($logicalId)) {
          $calendarIntKeys[(int) $logicalId] = true;
        }
      }
    }

    $lookupStringKeys = array_keys($pkStringKeys + $logicalStringKeys);

    $calendarMap = [];
    $calendarTable = 'calendario_actividades';
    if (!empty($calendarIntKeys) && $this->schema->tableExists($calendarTable)) {
      $ticketIds = array_keys($calendarIntKeys);
      $ph    = implode(',', array_fill(0, count($ticketIds), '?'));
      $hasEventId = $this->schema->columnExists($calendarTable, 'id');
      $eventSelect = $hasEventId ? ", MIN(`id`) AS `evento_id`" : '';
      $sql   = "SELECT `id_ticket`, MIN(`fecha_inicio`) AS `fecha_inicio`{$eventSelect} FROM `{$calendarTable}` WHERE `id_ticket` IN ({$ph}) GROUP BY `id_ticket`";
      $items = $this->db->getResults($sql, $ticketIds);
      foreach ($items as $item) {
        $k = trim((string) ($item['id_ticket'] ?? ''));
        if ($k === '') {
          continue;
        }
        $calendarMap[$k] = [
          'fecha_inicio' => $item['fecha_inicio'] ?? '',
          'evento_id' => $hasEventId ? trim((string) ($item['evento_id'] ?? '')) : '',
        ];
      }
    }

    $corrTable = $this->db->table('jet_cct_revision_correctiva');
    $corrRowsByTicket = $this->fetchRowsByTicketForeignKey(
      $corrTable,
      $lookupStringKeys,
      [
        '_ID',
        'id_ticket',
        'fecha',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'estado',
        'estado_rev_correctiva',
        'estado_revision_correctiva',
      ]
    );

    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $cotRowsByTicket = $this->fetchRowsByTicketForeignKey(
      $cotTable,
      $lookupStringKeys,
      [
        '_ID',
        'id_ticket',
        'fecha',
        'fecha_envio',
        'fecha_respuesta',
        'inicio_trabajo',
        'final_trabajo',
        'estado',
        'estado_respuesta',
        'estado_cotizacion_mantenimiento',
        'estado_respuesta_cotizacion_mantenimiento',
        'se_envio',
        'fue_enviada_cotizacion_mantenimiento',
        'fue_enviada',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
      ]
    );

    foreach ($rows as &$row) {
      $keys = $this->resolveTicketLookupKeys($row);
      if (empty($keys)) {
        continue;
      }

      foreach ($keys as $key) {
        if (isset($calendarMap[$key])) {
          $row['_scm_cita_cal_fecha'] = $this->parser->parse((string) ($calendarMap[$key]['fecha_inicio'] ?? ''));
          $row['_scm_cita_cal_id'] = trim((string) ($calendarMap[$key]['evento_id'] ?? ''));
          break;
        }
      }

      $corrCandidates = [];
      foreach ($keys as $key) {
        if (!empty($corrRowsByTicket[$key])) {
          $corrCandidates = array_merge($corrCandidates, $corrRowsByTicket[$key]);
        }
      }
      $latestCorr = $this->pickLatestRowByDateCandidates(
        $corrCandidates,
        ['fecha', 'cct_created', 'cct_modified', 'created_at', 'updated_at']
      );
      if (!empty($latestCorr)) {
        $corrRaw = $this->firstExistingValue($latestCorr, ['fecha', 'cct_created', 'cct_modified', 'created_at', 'updated_at']);
        $corrTs = $this->parser->parse($corrRaw);
        if ($corrTs > 0) {
          $row['_scm_corr_fecha'] = $corrTs;
        }

        $corrEstado = $this->firstExistingValue($latestCorr, ['estado_rev_correctiva', 'estado_revision_correctiva', 'estado']);
        if ($corrEstado !== '' && trim((string) ($row['estado_rev_correctiva'] ?? '')) === '') {
          $row['estado_rev_correctiva'] = $corrEstado;
        }
      }

      $cotCandidates = [];
      foreach ($keys as $key) {
        if (!empty($cotRowsByTicket[$key])) {
          $cotCandidates = array_merge($cotCandidates, $cotRowsByTicket[$key]);
        }
      }
      $latestCot = $this->pickLatestRowByDateCandidates(
        $cotCandidates,
        ['cct_created', 'created_at', 'fecha', 'cct_modified', 'updated_at']
      );
      if (!empty($latestCot)) {
        $cotRegRaw = $this->firstExistingValue($latestCot, ['cct_created', 'created_at', 'fecha', 'cct_modified', 'updated_at']);
        $cotSentFlag = $this->firstExistingValue($latestCot, ['se_envio', 'fue_enviada_cotizacion_mantenimiento', 'fue_enviada']);
        $cotSentOk = in_array(strtolower(trim((string) $cotSentFlag)), ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
        $cotSendRaw = $cotSentOk ? $this->firstExistingValue($latestCot, ['fecha_envio', 'fecha_envio_cotizacion_mantenimiento', 'fecha_enviada', 'fecha_envio_correo']) : '';
        $cotRespRaw = $this->firstExistingValue($latestCot, ['fecha_respuesta', 'cct_modified', 'updated_at']);
        $cotStartRaw = $this->firstExistingValue($latestCot, ['inicio_trabajo']);
        $cotEndRaw = $this->firstExistingValue($latestCot, ['final_trabajo']);

        $cotRegTs = $this->parser->parse($cotRegRaw);
        if ($cotRegTs > 0) {
          $row['_scm_cot_fecha_registro'] = $cotRegTs;
        }

        $cotSendTs = $this->parser->parse($cotSendRaw);
        if ($cotSendTs > 0) {
          $row['_scm_cot_fecha_envio'] = $cotSendTs;
        }

        $cotRespTs = $this->parser->parse($cotRespRaw);
        if ($cotRespTs > 0) {
          $row['_scm_cot_fecha_respuesta'] = $cotRespTs;
          $row['fecha_respuesta_cotizacion_mantenimiento'] = $cotRespTs;
        }

        $cotStartTs = $this->parser->parse($cotStartRaw);
        if ($cotStartTs > 0) {
          $row['_scm_cot_inicio_trabajo'] = $cotStartTs;
        }

        $cotEndTs = $this->parser->parse($cotEndRaw);
        if ($cotEndTs > 0) {
          $row['_scm_cot_final_trabajo'] = $cotEndTs;
        }

        $cotEstado = $this->firstExistingValue($latestCot, ['estado_cotizacion_mantenimiento', 'estado']);
        if ($cotEstado !== '' && trim((string) ($row['estado_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_cotizacion_mantenimiento'] = $cotEstado;
        }
        if ($cotSentFlag !== '' && trim((string) ($row['fue_enviada_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['fue_enviada_cotizacion_mantenimiento'] = $cotSentFlag;
        }
        if ($cotSentFlag !== '' && trim((string) ($row['se_envio'] ?? '')) === '') {
          $row['se_envio'] = $cotSentFlag;
        }

        $cotRespEstado = $this->firstExistingValue($latestCot, ['estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta']);
        if ($cotRespEstado !== '' && trim((string) ($row['estado_respuesta_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_respuesta_cotizacion_mantenimiento'] = $cotRespEstado;
        }
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
}
