<?php

declare(strict_types=1);

namespace SCM\Modules\ServiciosInmobiliarios\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

trait PersistenceAndContactsConcern
{
  public function saveNote(int $ticketPk, string $observacion): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $notesTable   = $this->db->table('jet_cct_notas_ticket');

    if ($ticketPk <= 0) {
      return ['ok' => '0', 'message' => 'Ticket invalido.'];
    }
    if (!$this->schema->tableExists($ticketsTable)) {
      return ['ok' => '0', 'message' => 'No existe tabla de tickets.'];
    }
    if (!$this->schema->tableExists($notesTable)) {
      return ['ok' => '0', 'message' => 'No existe tabla de notas.'];
    }

    $ticket = $this->db->getRow(
      "SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1",
      [$ticketPk]
    );
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }

    $nowTs    = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId   = \SCM\Core\Auth::userId();
    $userName = \SCM\Core\Auth::user();
    if ($userName === '') {
      $userName = $userId > 0 ? ('Usuario #' . $userId) : 'Visitante';
    }
    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);

    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketPk,
      'id_empleado' => $employeeId,
      'fecha' => $nowTs,
      'nombre' => $userName,
      'observacion' => $observacion,
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
    ];

    $payload = $this->schema->filterTableData($notesTable, $payload);
    if (empty($payload) || !$this->db->insert($notesTable, $payload)) {
      return ['ok' => '0', 'message' => 'No se pudo insertar la nota.'];
    }

    $currentSeg = isset($ticket['seguimientos']) ? (int) $ticket['seguimientos'] : 0;
    $ticketUpdate = [
      'seguimientos' => (string) ($currentSeg + 1),
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
    ];
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (!empty($ticketUpdate)) {
      $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    }

    return [
      'ok' => '1',
      'message' => 'Nota guardada.',
      'note_id' => $this->db->lastInsertId(),
    ];
  }

  /**
   * @param array<string,string> $data
   * @return array<string,string>
   */
  public function saveContactData(int $ticketPk, array $data): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if ($ticketPk <= 0 || !$this->schema->tableExists($ticketsTable)) {
      return ['ok' => '0', 'message' => 'Ticket invalido.'];
    }

    $ticket = $this->db->getRow("SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }

    $nowMysql = date('Y-m-d H:i:s');
    $ticketUpdate = [
      'propietario' => $data['propietario'] ?? '',
      'correo_propietario' => $data['correo_propietario'] ?? '',
      'celular_propietario' => $data['celular_propietario'] ?? '',
      'arrendatario' => $data['arrendatario'] ?? '',
      'correo_arrendatario' => $data['correo_arrendatario'] ?? '',
      'celular_arrendatario' => $data['celular_arrendatario'] ?? '',
      'indicativo' => $this->firstNonEmpty([
        $this->normalizeIndicativo((string)($data['indicativo_arrendatario'] ?? '')),
        $this->normalizeIndicativo((string)($data['indicativo_propietario'] ?? '')),
      ]),
      'indicativo_propietario' => $this->normalizeIndicativo((string)($data['indicativo_propietario'] ?? '')),
      'indicativo_arrendatario' => $this->normalizeIndicativo((string)($data['indicativo_arrendatario'] ?? '')),
      'fecha_actualizacion' => time(),
      'cct_modified' => $nowMysql,
    ];

    $oldSolicitante = strtolower(trim((string)($ticket['solicitante'] ?? '')));
    $oldSolicitanteEmail = strtolower(trim((string)($ticket['correo_solicitante'] ?? '')));
    $oldPropietario = strtolower(trim((string)($ticket['propietario'] ?? '')));
    $oldPropietarioEmail = strtolower(trim((string)($ticket['correo_propietario'] ?? '')));
    $oldArrendatario = strtolower(trim((string)($ticket['arrendatario'] ?? '')));
    $oldArrendatarioEmail = strtolower(trim((string)($ticket['correo_arrendatario'] ?? '')));
    if ($oldSolicitante !== '' && $oldSolicitante === $oldPropietario) {
      $ticketUpdate['solicitante'] = $data['propietario'] ?? '';
    } elseif ($oldSolicitante !== '' && $oldSolicitante === $oldArrendatario) {
      $ticketUpdate['solicitante'] = $data['arrendatario'] ?? '';
    }
    if ($oldSolicitanteEmail !== '' && $oldSolicitanteEmail === $oldPropietarioEmail) {
      $ticketUpdate['correo_solicitante'] = $data['correo_propietario'] ?? '';
    } elseif ($oldSolicitanteEmail !== '' && $oldSolicitanteEmail === $oldArrendatarioEmail) {
      $ticketUpdate['correo_solicitante'] = $data['correo_arrendatario'] ?? '';
    }

    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    $ticketRows = !empty($ticketUpdate) ? $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]) : 0;

    $contractRows = $this->updateContratoContactos($ticket, $data, $nowMysql);
    $ownerRows = $this->updatePersonaContactos(
      $this->db->table('jet_cct_propietarios'),
      ['_ID', 'id_propietario'],
      (string)($ticket['id_propietario'] ?? ''),
      [
        'nombre' => $data['propietario'] ?? '',
        'correo' => $data['correo_propietario'] ?? '',
        'celular' => $data['celular_propietario'] ?? '',
        'indicativo' => $this->normalizeIndicativo((string)($data['indicativo_propietario'] ?? '')),
        'cct_modified' => $nowMysql,
      ]
    );
    $tenantRows = $this->updatePersonaContactos(
      $this->db->table('jet_cct_arrendatarios'),
      ['id_arrendatario'],
      (string)($ticket['id_arrendatario'] ?? ''),
      [
        'nombre' => $data['arrendatario'] ?? '',
        'correo' => $data['correo_arrendatario'] ?? '',
        'celular' => $data['celular_arrendatario'] ?? '',
        'indicativo' => $this->normalizeIndicativo((string)($data['indicativo_arrendatario'] ?? '')),
        'cct_modified' => $nowMysql,
      ]
    );

    return [
      'ok' => '1',
      'message' => 'Contactos actualizados.',
      'ticket_rows' => (string)$ticketRows,
      'contract_rows' => (string)$contractRows,
      'owner_rows' => (string)$ownerRows,
      'tenant_rows' => (string)$tenantRows,
    ];
  }

  private function insertHistorial(string $histTable, int $ticketPk, string $observacion, int $userId, string $employeeId, string $userName, int $nowTs, string $nowMysql, string $estadoTicket, string $estadoCotizacion, string $estadoAdministrativo, $imagenes = '', array $documentos = []): bool
  {
    if (!$this->schema->tableExists($histTable)) {
      return false;
    }

    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketPk,
      'fecha' => $nowTs,
      'nombre' => $userName,
      'respuesta' => $observacion,
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $employeeId,
      'fue_editada' => 'Si',
    ];
    $imagenes = is_array($imagenes) ? array_values(array_filter(array_map('strval', $imagenes))) : [trim((string) $imagenes)];
    $imagenes = array_values(array_filter($imagenes, static fn(string $url): bool => $url !== ''));
    if (count($imagenes) === 1) {
      $payload['imagen'] = $imagenes[0];
    } elseif (count($imagenes) > 1) {
      $payload['imagen'] = serialize($imagenes);
    }
    $documentos = array_values(array_filter($documentos, static function ($doc): bool {
      return is_array($doc) && trim((string) ($doc['archivo'] ?? '')) !== '';
    }));
    if (!empty($documentos)) {
      $payload['archivos'] = serialize($documentos);
    }

    $payload = $this->schema->filterTableData($histTable, $payload);
    if (empty($payload)) {
      return false;
    }

    return $this->db->insert($histTable, $payload);
  }

  private function insertHistorialInmuebleCotizacion(array $ticket, string $estado, string $observacion, string $motivo, int $nowTs, string $nowMysql): bool
  {
    $histTable = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($histTable)) {
      return false;
    }

    $userName = \SCM\Core\Auth::user();
    $userId = \SCM\Core\Auth::userId();
    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);
    if ($userName === '') {
      $userName = $userId > 0 ? ('Usuario #' . $userId) : 'Sistema';
    }

    $detail = 'Respuesta de cotizacion de mantenimiento: ' . $estado . '.';
    if ($observacion !== '') {
      $detail .= ' Observacion: ' . wp_strip_all_tags($observacion) . '.';
    }
    if ($estado === 'Desaprobada' && trim($motivo) !== '') {
      $detail .= ' Motivo: ' . trim($motivo) . '.';
    }

    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_ticket' => $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']),
      'id_inmueble' => $this->firstNonEmpty([$ticket['id_inmueble'] ?? '', $ticket['inmueble'] ?? '']),
      'id_inmueble_data' => $this->firstNonEmpty([$ticket['id_inmueble_data'] ?? '', $ticket['id_inmueble'] ?? '', $ticket['inmueble'] ?? '']),
      'id_empleado' => $employeeId,
      'fecha' => $nowTs,
      'tipo_reporte' => 'Respuesta cotizacion mantenimiento',
      'observacion' => $detail,
      'funcionario' => $userName,
    ];
    $payload = $this->schema->filterTableData($histTable, $payload);
    if (empty($payload)) {
      return false;
    }
    return $this->db->insert($histTable, $payload);
  }

  /** @param array<string,mixed> $ticket @param array<string,string> $data */
  private function updateContratoContactos(array $ticket, array $data, string $nowMysql): int
  {
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($contractTable)) {
      return 0;
    }

    $payload = [
      'propietario' => $data['propietario'] ?? '',
      'correo_propietario' => $data['correo_propietario'] ?? '',
      'celular_propietario' => $data['celular_propietario'] ?? '',
      'arrendatario' => $data['arrendatario'] ?? '',
      'correo_arrendatario' => $data['correo_arrendatario'] ?? '',
      'celular_arrendatario' => $data['celular_arrendatario'] ?? '',
      'indicativo_propietario' => $this->normalizeIndicativo((string)($data['indicativo_propietario'] ?? '')),
      'indicativo_arrendatario' => $this->normalizeIndicativo((string)($data['indicativo_arrendatario'] ?? '')),
      'cct_modified' => $nowMysql,
    ];
    $payload = $this->schema->filterTableData($contractTable, $payload);
    if (empty($payload)) {
      return 0;
    }

    $rows = 0;
    $ids = [
      '_ID' => trim((string)($ticket['id_contrato'] ?? '')),
      'contrato' => trim((string)($ticket['contrato'] ?? '')),
    ];
    foreach ($ids as $column => $value) {
      if ($value === '' || !$this->schema->columnExists($contractTable, $column)) {
        continue;
      }
      $updated = $this->db->update($contractTable, $payload, [$column => $value]);
      if ($updated > 0) {
        $rows += $updated;
        break;
      }
    }
    return $rows;
  }

  /** @param string[] $idColumns @param array<string,string> $payload */
  private function updatePersonaContactos(string $table, array $idColumns, string $id, array $payload): int
  {
    $id = trim($id);
    if ($id === '' || !$this->schema->tableExists($table)) {
      return 0;
    }

    $payload = $this->schema->filterTableData($table, $payload);
    if (empty($payload)) {
      return 0;
    }

    $rows = 0;
    foreach ($idColumns as $column) {
      if (!$this->schema->columnExists($table, $column)) {
        continue;
      }
      $updated = $this->db->update($table, $payload, [$column => $id]);
      if ($updated > 0) {
        $rows += $updated;
        break;
      }
    }
    return $rows;
  }

  private function normalizeIndicativo(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    $value = preg_replace('/[^0-9+]/', '', $value);
    if (!is_string($value) || $value === '') {
      return '';
    }
    return $value[0] === '+' ? $value : ('+' . ltrim($value, '+'));
  }

  /** @param string[] $cotIds */
  private function updateCotizacionesByIds(string $cotTable, array $cotIds, array $cotUpdate): int
  {
    if (empty($cotUpdate)) {
      return 0;
    }
    $cotIdCol = $this->schema->detectFirstExistingColumn($cotTable, ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id']);
    if ($cotIdCol === '') {
      return 0;
    }

    $rows = 0;
    foreach ($cotIds as $cotId) {
      if ($cotId === '') {
        continue;
      }
      $rows += $this->db->update($cotTable, $cotUpdate, [$cotIdCol => $cotId]);
    }
    return $rows;
  }

  private function fetchCotizacion(string $cotTable, string $cotId): ?array
  {
    $cotIdCol = $this->schema->detectFirstExistingColumn($cotTable, ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id']);
    if ($cotIdCol === '' || $cotId === '') {
      return null;
    }
    return $this->db->getRow("SELECT * FROM `{$cotTable}` WHERE `{$cotIdCol}` = ? LIMIT 1", [$cotId]);
  }

  private function findFuncionario($userId): array
  {
    $id = trim((string)$userId);
    if ($id === '' || $id === '0') {
      return [];
    }
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]);
    if (!is_array($row) || $row === []) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `id_empleado` = ? LIMIT 1", [$id]);
    }
    return is_array($row) ? $row : [];
  }

  /** @param array<string,mixed> $userInfo */
  private function employeeLogicalId(int $authorId, array $userInfo): string
  {
    $employeeId = trim((string)($userInfo['id_empleado'] ?? ''));
    return $employeeId !== '' ? $employeeId : (string)$authorId;
  }

  private function findFuncionarioByEmployeeId($employeeId): array
  {
    $id = trim((string)$employeeId);
    if ($id === '' || $id === '0') {
      return [];
    }
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `id_empleado` = ? LIMIT 1", [$id]);
    return is_array($row) ? $row : [];
  }

  /** @param array<string,mixed> $ticket */
  private function ticketEmployeeId(array $ticket, string $fallbackEmployeeId): string
  {
    $ticketEmployeeId = trim((string)($ticket['id_empleado'] ?? ''));
    return $ticketEmployeeId !== '' ? $ticketEmployeeId : $fallbackEmployeeId;
  }
}
