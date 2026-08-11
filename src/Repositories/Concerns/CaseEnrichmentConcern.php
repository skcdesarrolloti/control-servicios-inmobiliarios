<?php

declare(strict_types=1);

namespace SCM\Repositories\Concerns;

trait CaseEnrichmentConcern
{
  private function enrichRowsWithCaseDetails(array $rows): array
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
      foreach ($this->resolveTicketLookupKeys($row) as $key) {
        $ticketKeys[$key] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['contrato', 'id_contrato'])) as $id) {
        $contractIds[$id] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['id_inmueble'])) as $id) {
        $propertyIds[$id] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['id_propietario'])) as $id) {
        $ownerIds[$id] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['id_arrendatario'])) as $id) {
        $tenantIds[$id] = true;
      }
    }

    $segByTicket = $this->fetchRowsGroupedByColumnCandidates(
      $this->db->table('jet_cct_seguimiento_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified', 'id_coordinador', 'id_empleado', 'evidencia']
    );
    $notesByTicket = $this->fetchRowsGroupedByColumnCandidates(
      $this->db->table('jet_cct_notas_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'id_empleado', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified']
    );
    $contractById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_contratos_arrendamiento'),
      ['_ID', 'contrato'],
      array_keys($contractIds)
    );
    $propertyById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_inmuebles'),
      ['codigo'],
      array_keys($propertyIds)
    );
    $ownerById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_propietarios'),
      ['id_propietario'],
      array_keys($ownerIds)
    );
    $tenantById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_arrendatarios'),
      ['id_arrendatario'],
      array_keys($tenantIds)
    );
    $histByProperty = $this->fetchRowsGroupedByColumnCandidates(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_inmueble', 'id_inmueble_data'],
      array_keys($propertyIds),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id']
    );
    $histByTicket = $this->fetchRowsGroupedByColumnCandidates(
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

      foreach ($this->resolveTicketLookupKeys($row) as $key) {
        if (!empty($segByTicket[$key])) {
          $row['_scm_seguimientos_ticket'] = array_merge($row['_scm_seguimientos_ticket'], $segByTicket[$key]);
        }
        if (!empty($notesByTicket[$key])) {
          $row['_scm_notas_ticket'] = array_merge($row['_scm_notas_ticket'], $notesByTicket[$key]);
        }
      }

      $contractRaw = $this->firstExistingValue($row, ['contrato', 'id_contrato']);
      $contractId = $this->first_id_value($contractRaw);
      if ($contractId !== '' && isset($contractById[$contractId])) {
        $row['_scm_contrato_data'] = $contractById[$contractId];
      }

      $propertyRaw = $this->firstExistingValue($row, ['id_inmueble']);
      $propertyId = $this->first_id_value($propertyRaw);
      if ($propertyId === '' && !empty($row['_scm_contrato_data']) && is_array($row['_scm_contrato_data'])) {
        $propertyId = $this->first_id_value($this->firstExistingValue(
          $row['_scm_contrato_data'],
          ['id_inmueble']
        ));
      }
      if ($propertyId !== '' && isset($propertyById[$propertyId])) {
        $row['_scm_inmueble_data'] = $propertyById[$propertyId];
      }
      $ownerId = $this->first_id_value($this->firstExistingValue($row, ['id_propietario']));
      if ($ownerId !== '' && isset($ownerById[$ownerId])) {
        $row['_scm_propietario_data'] = $ownerById[$ownerId];
        if (trim((string)($row['indicativo_propietario'] ?? '')) === '') {
          $row['indicativo_propietario'] = trim((string)($ownerById[$ownerId]['indicativo'] ?? ''));
        }
      }
      $tenantId = $this->first_id_value($this->firstExistingValue($row, ['id_arrendatario']));
      if ($tenantId !== '' && isset($tenantById[$tenantId])) {
        $row['_scm_arrendatario_data'] = $tenantById[$tenantId];
        if (trim((string)($row['indicativo_arrendatario'] ?? '')) === '') {
          $row['indicativo_arrendatario'] = trim((string)($tenantById[$tenantId]['indicativo'] ?? ''));
        }
      }
      if ($propertyId !== '' && isset($histByProperty[$propertyId])) {
        $row['_scm_historial_inmueble'] = $histByProperty[$propertyId];
      }
      foreach ($this->resolveTicketLookupKeys($row) as $key) {
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

      usort($row['_scm_seguimientos_ticket'], fn(array $a, array $b): int => $this->parser->parse($b['fecha'] ?? $b['cct_created'] ?? '') <=> $this->parser->parse($a['fecha'] ?? $a['cct_created'] ?? ''));
      usort($row['_scm_notas_ticket'], fn(array $a, array $b): int => $this->parser->parse($b['fecha'] ?? $b['cct_created'] ?? '') <=> $this->parser->parse($a['fecha'] ?? $a['cct_created'] ?? ''));
      usort($row['_scm_historial_inmueble'], fn(array $a, array $b): int => $this->parser->parse($b['fecha'] ?? $b['cct_created'] ?? '') <=> $this->parser->parse($a['fecha'] ?? $a['cct_created'] ?? ''));
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
}
