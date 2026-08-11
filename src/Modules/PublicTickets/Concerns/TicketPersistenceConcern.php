<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait TicketPersistenceConcern
{
  private function insertTicket(
    string $actor,
    array $config,
    string $tipoPqrs,
    string $departamento,
    string $asunto,
    string $descripcion,
    array $input,
    array $contact,
    array $responsable,
    ?array $contract,
    ?array $client
  ): array {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable)) {
      return ['ok' => false, 'message' => 'No existe la tabla de tickets.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $actorLabel = $this->getPqrCreatedByLabel($actor, $config);
    $clientPk = $actor === 'cliente' && is_array($client)
      ? trim((string) ($client['_ID'] ?? ''))
      : '';

    $data = [
      'cct_status' => 'publish',
      'cct_author_id' => 0,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_ticket' => '',
      'id_creador' => $clientPk !== '' ? $clientPk : (string) $contact['actor_id'],
      'asunto' => $asunto,
      'descripcion' => $descripcion,
      'fecha_actualizacion' => $nowTs,
      'empleado' => (string) ($responsable['nombre'] ?? ''),
      'id_empleado' => (string) ($responsable['id_empleado'] ?? ''),
      'nombre_empleado' => (string) ($responsable['nombre'] ?? ''),
      'correo_empleado' => (string) ($responsable['correo'] ?? ''),
      'celular_empleado' => (string) ($responsable['celular'] ?? ''),
      'prioridad' => 'Prioridad urgente',
      'tema_ayuda' => $tipoPqrs,
      'departamento' => $departamento,
      'estado' => 'Nuevo',
      'estado_rev_correctiva' => 'No',
      'estado_rev_preventiva' => 'No',
      'estado_cotizacion_mantenimiento' => 'No',
      'estado_acta_satisfaccion' => 'No',
      'tuvo_reporte' => 'No',
      'medio' => (string) ($config['medium'] ?? 'Portal autoservicio'),
      'creado_por' => $actorLabel,
      'creador_por' => $actorLabel,
      'solicitante' => (string) $contact['name'],
      'correo_solicitante' => (string) $contact['email'],
      'celular_solicitante' => (string) $contact['phone'],
      'indicativo' => (string) $contact['indicativo'],
      'fecha' => $nowTs,
      'id_solicitante' => $clientPk !== '' ? $clientPk : (string) $contact['actor_id'],
      'seguimientos' => '0',
      'tuvo_seguimiento' => 'No',
    ];

    if ($actor === 'cliente' || in_array($tipoPqrs, ['Captacion', 'Avaluo'], true)) {
      $data['estado_comercial'] = 'Nuevo';
    } else {
      $data['estado_administrativo'] = 'Nuevo';
    }

    $tipoInmueble = trim((string) ($input['tipo_inmueble'] ?? ''));
    if ($tipoInmueble !== '') {
      $data['tipo_inmueble'] = $tipoInmueble;
    }

    $destinacion = trim((string) ($input['destinacion'] ?? ''));
    if ($destinacion !== '') {
      $data['destinacion'] = $destinacion;
    }

    $presupuesto = trim((string) ($input['presupuesto'] ?? ''));
    if ($presupuesto !== '') {
      $data['presupuesto'] = $presupuesto;
    }

    $sucursal = trim((string) ($input['sucursal'] ?? ''));
    if ($sucursal !== '') {
      $data['sucursal'] = $sucursal;
    }

    $publishedPropertyFields = [
      'id_inmueble',
      'id_inmueble_data',
      'codigo_inmueble',
      'direccion',
      'barrio',
      'ciudad',
      'habitaciones',
      'banos',
      'precio_arriendo',
      'precio_venta',
      'precio_admin',
      'area_construida',
      'area_terreno',
    ];
    foreach ($publishedPropertyFields as $field) {
      $value = trim((string) ($input[$field] ?? ''));
      if ($value !== '') {
        $data[$field] = $value;
      }
    }

    if (is_array($contract)) {
      $data['id_contrato'] = trim((string) ($contract['_ID'] ?? ''));
      $data['contrato'] = trim((string) ($contract['contrato'] ?? ''));
      $data['id_inmueble'] = trim((string) ($contract['id_inmueble'] ?? ''));
      $data['id_inmueble_data'] = trim((string) ($contract['id_inmueble_data'] ?? ''));
      $data['inmueble'] = trim((string) ($contract['inmueble'] ?? ''));
      $data['direccion'] = trim((string) ($contract['direccion'] ?? ''));
      $data['barrio'] = trim((string) ($contract['barrio'] ?? ''));
      $data['sucursal'] = trim((string) ($contract['sucursal'] ?? ''));
      $data['id_propietario'] = trim((string) ($contract['id_propietario'] ?? ''));
      $data['id_arrendatario'] = trim((string) ($contract['id_arrendatario'] ?? ''));
      $data['propietario'] = trim((string) ($contract['propietario'] ?? ''));
      $data['correo_propietario'] = trim((string) ($contract['correo_propietario'] ?? ''));
      $data['celular_propietario'] = trim((string) ($contract['celular_propietario'] ?? ''));
      $data['indicativo_propietario'] = $this->normalizeIndicativo((string) ($contract['indicativo_propietario'] ?? ''));
      $data['arrendatario'] = trim((string) ($contract['arrendatario'] ?? ''));
      $data['correo_arrendatario'] = trim((string) ($contract['correo_arrendatario'] ?? ''));
      $data['celular_arrendatario'] = trim((string) ($contract['celular_arrendatario'] ?? ''));
      $data['indicativo_arrendatario'] = $this->normalizeIndicativo((string) ($contract['indicativo_arrendatario'] ?? ''));
    } elseif ($actor === 'propietario') {
      $data['id_propietario'] = (string) $contact['actor_id'];
      $data['propietario'] = (string) $contact['name'];
      $data['correo_propietario'] = (string) $contact['email'];
      $data['celular_propietario'] = (string) $contact['phone'];
      $data['indicativo_propietario'] = (string) $contact['indicativo'];
    } elseif ($actor === 'arrendatario') {
      $data['id_arrendatario'] = (string) $contact['actor_id'];
      $data['arrendatario'] = (string) $contact['name'];
      $data['correo_arrendatario'] = (string) $contact['email'];
      $data['celular_arrendatario'] = (string) $contact['phone'];
      $data['indicativo_arrendatario'] = (string) $contact['indicativo'];
    }

    if ($actor === 'cliente' && is_array($client)) {
      $data['id_cliente'] = $clientPk;
    }

    $insertData = $this->schema->filterTableData($ticketsTable, $data);
    if (empty($insertData)) {
      return ['ok' => false, 'message' => 'No se pudo construir el payload del ticket.'];
    }

    try {
      $this->db->insert($ticketsTable, $insertData);
      $ticketId = (int) $this->db->lastInsertId();
    } catch (\Throwable $e) {
      return ['ok' => false, 'message' => 'Error insertando ticket: ' . $e->getMessage()];
    }

    if ($ticketId <= 0) {
      return ['ok' => false, 'message' => 'No se pudo obtener el ID del ticket creado.'];
    }

    $updateData = $this->schema->filterTableData($ticketsTable, [
      'id_ticket' => (string) $ticketId,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
    ]);
    if (!empty($updateData)) {
      $this->db->update($ticketsTable, $updateData, ['_ID' => $ticketId]);
    }

    return ['ok' => true, 'ticket_id' => $ticketId];
  }

  /**
   * @param array<string,mixed>|null $contract
   * @param array<string,mixed>|null $client
   * @return array<int,array<string,mixed>>
   */
  private function findRequestsForSelection(string $actor, ?array $contract, ?array $client, array $options = []): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable)) {
      return [];
    }

    $where = [];
    $args = [];
    $extraWhere = [];
    if ($actor !== 'cliente') {
      $actorLabel = mb_strtolower($this->getPqrCreatedByLabel($actor, self::getActorConfig($actor)), 'UTF-8');
      if ($this->schema->columnExists($ticketsTable, 'creado_por')) {
        $where[] = "LOWER(TRIM(COALESCE(`creado_por`, ''))) = ?";
        $args[] = $actorLabel;
      }
      if ($this->schema->columnExists($ticketsTable, 'creador_por')) {
        $where[] = "LOWER(TRIM(COALESCE(`creador_por`, ''))) = ?";
        $args[] = $actorLabel;
      }
      if ($this->schema->columnExists($ticketsTable, 'medio')) {
        $where[] = "LOWER(TRIM(COALESCE(`medio`, ''))) = ?";
        $args[] = mb_strtolower(trim((string) (self::getActorConfig($actor)['medium'] ?? '')), 'UTF-8');
      }
    }

    $identityClauses = [];
    if (!empty($options['all_by_solicitante'])) {
      $actorIds = [];
      if (is_array($options['actor_ids'] ?? null)) {
        foreach ($options['actor_ids'] as $actorId) {
          $actorId = trim((string) $actorId);
          if ($actorId !== '' && !in_array($actorId, $actorIds, true)) {
            $actorIds[] = $actorId;
          }
        }
      }

      $actorId = trim((string) ($options['actor_id'] ?? ''));
      if ($actorId !== '' && !in_array($actorId, $actorIds, true)) {
        $actorIds[] = $actorId;
      }

      $lookupValue = trim((string) ($options['lookup_value'] ?? ''));
      if ($lookupValue !== '') {
        if ($actor === 'propietario') {
          foreach (array_keys($this->findOwnerIdsByDocument($lookupValue, $this->normalizeAlphaNum($lookupValue))) as $ownerId) {
            if ($ownerId !== '' && !in_array($ownerId, $actorIds, true)) {
              $actorIds[] = $ownerId;
            }
          }
        } elseif ($actor === 'arrendatario') {
          foreach (array_keys($this->findTenantIdsByDocument($lookupValue, $this->normalizeAlphaNum($lookupValue))) as $tenantId) {
            if ($tenantId !== '' && !in_array($tenantId, $actorIds, true)) {
              $actorIds[] = $tenantId;
            }
          }
        } elseif ($actor === 'copropiedad') {
          foreach (array_keys($this->findCopropiedadIds($lookupValue, $this->normalizeDigits($lookupValue))) as $coproId) {
            if ($coproId !== '' && !in_array($coproId, $actorIds, true)) {
              $actorIds[] = $coproId;
            }
          }
        }
      }

      if ($actorIds !== [] && $this->schema->columnExists($ticketsTable, 'id_solicitante')) {
        $identityClauses[] = "TRIM(COALESCE(`id_solicitante`, '')) IN (" . implode(', ', array_fill(0, count($actorIds), '?')) . ")";
        foreach ($actorIds as $actorIdItem) {
          $args[] = $actorIdItem;
        }
      }
    } elseif (is_array($contract)) {
      $contractId = trim((string) ($contract['_ID'] ?? ''));
      $contractCode = trim((string) ($contract['contrato'] ?? ''));
      $actorIdField = $actor === 'propietario' ? 'id_propietario' : ($actor === 'arrendatario' ? 'id_arrendatario' : 'id_copropiedad');
      $actorId = trim((string) ($contract[$actorIdField] ?? ''));

      if ($contractId !== '' && $this->schema->columnExists($ticketsTable, 'id_contrato')) {
        $identityClauses[] = "TRIM(COALESCE(`id_contrato`, '')) = ?";
        $args[] = $contractId;
      }
      if ($contractCode !== '' && $this->schema->columnExists($ticketsTable, 'contrato')) {
        $identityClauses[] = "TRIM(COALESCE(`contrato`, '')) = ?";
        $args[] = $contractCode;
      }
      if ($actorId !== '' && $this->schema->columnExists($ticketsTable, 'id_solicitante')) {
        $identityClauses[] = "TRIM(COALESCE(`id_solicitante`, '')) = ?";
        $args[] = $actorId;
      }
    } elseif (is_array($client)) {
      $clientId = trim((string) ($client['_ID'] ?? ''));
      if ($clientId !== '' && $this->schema->columnExists($ticketsTable, 'id_cliente')) {
        $identityClauses[] = "TRIM(COALESCE(`id_cliente`, '')) = ?";
        $args[] = $clientId;
      }
    } elseif (!empty($options['no_contract_commercial'])) {
      $actorId = trim((string) ($options['actor_id'] ?? ''));
      $actorIdField = $actor === 'propietario' ? 'id_propietario' : ($actor === 'arrendatario' ? 'id_arrendatario' : '');
      if ($actorId !== '' && $actorIdField !== '' && $this->schema->columnExists($ticketsTable, $actorIdField)) {
        $identityClauses[] = "TRIM(COALESCE(`{$actorIdField}`, '')) = ?";
        $args[] = $actorId;
      }
      if ($actorId !== '' && $this->schema->columnExists($ticketsTable, 'id_solicitante')) {
        $identityClauses[] = "TRIM(COALESCE(`id_solicitante`, '')) = ?";
        $args[] = $actorId;
      }
    }

    if (empty($identityClauses)) {
      return [];
    }

    if (empty($where)) {
      if ($actor === 'cliente') {
        $where[] = '1=1';
      } else {
        return [];
      }
    }

    $hasTemaAyuda = $this->schema->columnExists($ticketsTable, 'tema_ayuda');
    $hasTipoPqrs = $this->schema->columnExists($ticketsTable, 'tipo_pqrs');
    if ($hasTemaAyuda && $hasTipoPqrs) {
      $tipoExpr = "TRIM(COALESCE(`tema_ayuda`, `tipo_pqrs`, ''))";
    } elseif ($hasTemaAyuda) {
      $tipoExpr = "TRIM(COALESCE(`tema_ayuda`, ''))";
    } else {
      $tipoExpr = "TRIM(COALESCE(`tipo_pqrs`, ''))";
    }

    $temaFiltro = trim((string) ($options['tema_ayuda'] ?? ''));
    if ($temaFiltro !== '') {
      $extraWhere[] = "{$tipoExpr} = ?";
      $args[] = $temaFiltro;
    } else {
      $temaFiltros = [];
      if (is_array($options['tema_ayuda_in'] ?? null)) {
        foreach ($options['tema_ayuda_in'] as $temaItem) {
          $temaItem = trim((string) $temaItem);
          if ($temaItem !== '' && !in_array($temaItem, $temaFiltros, true)) {
            $temaFiltros[] = $temaItem;
          }
        }
      }

      if ($temaFiltros !== []) {
        $extraWhere[] = "{$tipoExpr} IN (" . implode(', ', array_fill(0, count($temaFiltros), '?')) . ")";
        foreach ($temaFiltros as $temaItem) {
          $args[] = $temaItem;
        }
      }
    }

    $idContratoExpr = $this->schema->columnExists($ticketsTable, 'id_contrato')
      ? "TRIM(COALESCE(`id_contrato`, ''))"
      : "''";

    $sql = "SELECT
              `_ID`,
              TRIM(COALESCE(`id_ticket`, '')) AS id_ticket,
              TRIM(COALESCE(`asunto`, '')) AS asunto,
              {$tipoExpr} AS tema_ayuda,
              TRIM(COALESCE(`estado`, '')) AS estado,
              TRIM(COALESCE(`departamento`, '')) AS departamento,
              COALESCE(`fecha_actualizacion`, `fecha`, 0) AS fecha_ref,
              {$idContratoExpr} AS id_contrato,
              TRIM(COALESCE(`contrato`, '')) AS contrato,
              TRIM(COALESCE(`inmueble`, '')) AS inmueble,
              TRIM(COALESCE(`direccion`, '')) AS direccion
            FROM `{$ticketsTable}`
            WHERE (" . implode(' OR ', $where) . ")
              AND (" . implode(' OR ', $identityClauses) . ")"
      . ($extraWhere !== [] ? " AND " . implode(' AND ', $extraWhere) : "") . "
            ORDER BY `_ID` DESC
            LIMIT 20";

    $rows = $this->db->getResults($sql, $args);
    $out = [];
    foreach ($rows as $row) {
      $logicalId = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalId === '') {
        $logicalId = trim((string) ($row['_ID'] ?? ''));
      }
      $timestamp = (int) ($row['fecha_ref'] ?? 0);
      $out[] = [
        'ticket_id' => $logicalId,
        'asunto' => trim((string) ($row['asunto'] ?? '')),
        'tema_ayuda' => trim((string) ($row['tema_ayuda'] ?? '')),
        'estado' => trim((string) ($row['estado'] ?? '')),
        'departamento' => trim((string) ($row['departamento'] ?? '')),
        'fecha' => $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '',
        'contrato' => trim((string) ($row['contrato'] ?? '')),
        'origen' => (trim((string) ($row['contrato'] ?? '')) !== '' || trim((string) ($row['id_contrato'] ?? '')) !== '') ? 'Contrato' : 'Comercial',
        'inmueble' => trim((string) ($row['inmueble'] ?? '')),
        'direccion' => trim((string) ($row['direccion'] ?? '')),
        'ticket_url' => $this->buildPublicTicketUrl($logicalId),
      ];
    }

    return $out;
  }

  private function buildPublicTicketUrl(string $logicalTicket): string
  {
    $logicalTicket = trim($logicalTicket);
    if ($logicalTicket === '') {
      return '';
    }

    return 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
  }

  private function buildClientQuoteUrl(string $quoteNumero): string
  {
    $quoteNumero = trim($quoteNumero);
    if ($quoteNumero === '') {
      return '';
    }

    return 'https://sucasainmobiliaria.com.co/cotizacion-de-inmuebles/?numero=' . rawurlencode($quoteNumero);
  }

  private function buildClientStudyUrl(string $studyPk): string
  {
    $studyPk = trim($studyPk);
    if ($studyPk === '') {
      return '';
    }

    return 'https://sucasainmobiliaria.com.co/estudio-aseguradora/?id_estudio=' . rawurlencode($studyPk);
  }

  private function insertTicketHistory(int $ticketPk, string $logicalTicket, string $actor, string $solicitante, string $responsableId): void
  {
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    if (!$this->schema->tableExists($historyTable)) {
      return;
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $obs = 'PQR creado desde portal de ' . ucfirst($actor) . '.';

    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketPk,
      'fecha' => $nowTs,
      'nombre' => $solicitante !== '' ? $solicitante : ucfirst($actor),
      'observacion' => $obs,
      'cct_author_id' => $responsableId !== '' ? $responsableId : 0,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $responsableId,
      'fue_editada' => 'No',
    ];

    $payload = $this->schema->filterTableData($historyTable, $payload);
    if (empty($payload)) {
      return;
    }

    try {
      $this->db->insert($historyTable, $payload);
    } catch (\Throwable $e) {
      // no-op
    }
  }

  /**
   * @param array{name:string,email:string,phone:string,indicativo:string,actor_id:string} $contact
   * @param array<string,string> $responsable
   * @param array<string,mixed>|null $contract
   * @param array<string,mixed>|null $client
   * @return array<string,mixed>
   */
}
