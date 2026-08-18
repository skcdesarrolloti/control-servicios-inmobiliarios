<?php

declare(strict_types=1);

namespace SCM\Modules\ServiciosInmobiliarios\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

trait WorkflowCommandsConcern
{
  public function save(int $ticketPk, string $observacion, string $estadoTicket, string $estadoCotizacion, string $estadoAdministrativo, bool $cerrarTicket, array $notifyTargets = [], $evidencias = '', array $documentos = [], string $observacionCotizacion = '', string $motivoCotizacion = '', string $financiacionCotizacion = ''): array
  {
    $ticketsTable      = $this->db->table('jet_cct_tickets');
    $seguimientoTable  = $this->db->table('jet_cct_seguimiento_ticket');
    $histFallbackTable = $this->db->table('jet_cct_historial_del_ticket');

    if ($ticketPk <= 0) {
      return ['ok' => '0', 'message' => 'Ticket invalido.'];
    }
    if (!$this->schema->tableExists($ticketsTable)) {
      return ['ok' => '0', 'message' => 'No existe tabla de tickets.'];
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

    $effectiveEstadoTicket = $cerrarTicket ? 'Cerrado' : 'En proceso';

    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);
    $evidencias = is_array($evidencias) ? array_values(array_filter(array_map('strval', $evidencias))) : [trim((string) $evidencias)];
    $evidencias = array_values(array_filter($evidencias, static fn(string $url): bool => $url !== ''));
    $evidenciaUrl = (string) ($evidencias[0] ?? '');
    $documentos = array_values(array_filter($documentos, static function ($doc): bool {
      return is_array($doc) && trim((string) ($doc['archivo'] ?? '')) !== '';
    }));

    $currentSeg = isset($ticket['seguimientos']) ? (int) $ticket['seguimientos'] : 0;
    $ticketUpdate = [
      'seguimientos' => (string) ($currentSeg + 1),
      'tuvo_seguimiento' => 'Si',
      'fecha_seguimiento' => $nowTs,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
      'id_encargado_seguimiento' => $employeeId,
      'estado' => $effectiveEstadoTicket,
    ];
    if ($estadoAdministrativo !== '' && $estadoAdministrativo !== '__keep__') {
      $ticketUpdate['estado_administrativo'] = $estadoAdministrativo;
    }
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (!empty($ticketUpdate)) {
      $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    }

    $segSaved = $this->insertSeguimientoTicket(
      $seguimientoTable,
      $ticket,
      $ticketPk,
      $observacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql,
      $evidenciaUrl
    );

    $histSaved = $this->insertHistorial(
      $histFallbackTable,
      $ticketPk,
      $observacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql,
      $effectiveEstadoTicket,
      $estadoCotizacion,
      $estadoAdministrativo,
      $evidencias,
      $documentos
    );

    $msg = 'Seguimiento guardado.';
    if ($effectiveEstadoTicket === 'Cerrado') {
      $msg .= ' Ticket cerrado.';
    }
    $emailsSent = $this->notifySeguimiento($ticket, $observacion, $userName, $notifyTargets);
    if ($emailsSent > 0) {
      $msg .= ' Correos programados en cola: ' . $emailsSent . '.';
    }
    $cotRows = '0';
    if ($this->shouldSaveCotizacionResponse($estadoCotizacion)) {
      $cotResult = $this->saveCotizacionResponse(
        $ticketPk,
        $estadoCotizacion,
        $observacionCotizacion !== '' ? $observacionCotizacion : 'Ninguna',
        $motivoCotizacion,
        $financiacionCotizacion,
        $notifyTargets
      );
      if (($cotResult['ok'] ?? '0') !== '1') {
        return $cotResult;
      }
      $cotRows = (string)($cotResult['cot_rows'] ?? '0');
      $msg .= ' Respuesta de cotizacion actualizada.';
    }

    return [
      'ok' => '1',
      'message' => $msg,
      'seg_saved' => $segSaved ? '1' : '0',
      'hist_saved' => $histSaved ? '1' : '0',
      'cot_rows' => $cotRows,
      'emails_sent' => (string)$emailsSent,
    ];
  }

  /**
   * @return array<string,string>
   */
  public function saveTicketResponse(int $ticketPk, string $respuesta, string $estadoAdministrativo, bool $cerrarTicket, array $notifyTargets = [], $imagenes = '', array $documentos = [], string $estadoCotizacion = '__keep__', string $observacionCotizacion = '', string $motivoCotizacion = '', string $financiacionCotizacion = ''): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $histTable    = $this->db->table('jet_cct_historial_del_ticket');
    if ($ticketPk <= 0 || !$this->schema->tableExists($ticketsTable) || !$this->schema->tableExists($histTable)) {
      return ['ok' => '0', 'message' => 'No se pudo preparar la respuesta.'];
    }

    $ticket = $this->db->getRow("SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = \SCM\Core\Auth::userId();
    $userName = \SCM\Core\Auth::user() ?: ($userId > 0 ? ('Usuario #' . $userId) : 'Sistema');
    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);
    $logicalTicket = trim((string)($ticket['id_ticket'] ?? ''));
    if ($logicalTicket === '') {
      $logicalTicket = (string)$ticketPk;
    }

    $imagenes = is_array($imagenes) ? array_values(array_filter(array_map('strval', $imagenes))) : [trim((string) $imagenes)];
    $imagenes = array_values(array_filter($imagenes, static fn(string $url): bool => $url !== ''));
    $documentos = array_values(array_filter($documentos, static function ($doc): bool {
      return is_array($doc) && trim((string) ($doc['archivo'] ?? '')) !== '';
    }));

    $histPayload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketPk,
      'fecha' => $nowTs,
      'nombre' => $userName,
      'correo' => (string)($userInfo['correo'] ?? $ticket['correo_empleado'] ?? ''),
      'celular' => (string)($userInfo['celular'] ?? $ticket['celular_empleado'] ?? ''),
      'respuesta' => $respuesta,
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $employeeId,
      'fue_editada' => 'No',
    ];
    if (count($imagenes) === 1) {
      $histPayload['imagen'] = $imagenes[0];
    } elseif (count($imagenes) > 1) {
      $histPayload['imagen'] = serialize($imagenes);
    }
    if (!empty($documentos)) {
      $histPayload['archivos'] = serialize($documentos);
    }
    $histPayload = $this->schema->filterTableData($histTable, $histPayload);
    if (empty($histPayload) || !$this->db->insert($histTable, $histPayload)) {
      return ['ok' => '0', 'message' => 'No se pudo guardar el historial.'];
    }

    $newEstado = $cerrarTicket ? 'Cerrado' : 'En proceso';
    $ticketUpdate = [
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
      'estado' => $newEstado,
    ];
    if ($estadoAdministrativo !== '' && $estadoAdministrativo !== '__keep__') {
      $ticketUpdate['estado_administrativo'] = $estadoAdministrativo;
    }
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (!empty($ticketUpdate)) {
      $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    }

    $sent = $this->notifyTicketResponse($ticket, $logicalTicket, $respuesta, $userName, $newEstado, $notifyTargets);
    $cotRows = '0';
    $extraMessage = '';
    if ($this->shouldSaveCotizacionResponse($estadoCotizacion)) {
      $cotResult = $this->saveCotizacionResponse(
        $ticketPk,
        $estadoCotizacion,
        $observacionCotizacion !== '' ? $observacionCotizacion : 'Ninguna',
        $motivoCotizacion,
        $financiacionCotizacion,
        $notifyTargets
      );
      if (($cotResult['ok'] ?? '0') !== '1') {
        return $cotResult;
      }
      $cotRows = (string)($cotResult['cot_rows'] ?? '0');
      $extraMessage = ' Respuesta de cotizacion actualizada.';
    }
    return [
      'ok' => '1',
      'message' => 'Respuesta guardada.' . $extraMessage . ($sent > 0 ? ' Correos programados en cola: ' . $sent . '.' : ' Sin correos programados.'),
      'emails_sent' => (string)$sent,
      'cot_rows' => $cotRows,
    ];
  }

  /**
   * @return array<string,string>
   */
  public function postponeTicket(int $ticketPk, string $observacion, array $notifyTargets = [], $evidencias = '', array $documentos = []): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $seguimientoTable = $this->db->table('jet_cct_seguimiento_ticket');
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($ticketPk <= 0 || !$this->schema->tableExists($ticketsTable) || !$this->schema->tableExists($histTable)) {
      return ['ok' => '0', 'message' => 'No se pudo preparar la postergacion.'];
    }

    $ticket = $this->db->getRow("SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = \SCM\Core\Auth::userId();
    $userName = \SCM\Core\Auth::user() ?: ($userId > 0 ? ('Usuario #' . $userId) : 'Sistema');
    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);
    $histObservacion = 'Ticket postergado: ' . $observacion;

    $evidencias = is_array($evidencias) ? array_values(array_filter(array_map('strval', $evidencias))) : [trim((string) $evidencias)];
    $evidencias = array_values(array_filter($evidencias, static fn(string $url): bool => $url !== ''));
    $evidenciaUrl = (string) ($evidencias[0] ?? '');
    $documentos = array_values(array_filter($documentos, static function ($doc): bool {
      return is_array($doc) && trim((string) ($doc['archivo'] ?? '')) !== '';
    }));

    $histSaved = $this->insertHistorial(
      $histTable,
      $ticketPk,
      $histObservacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql,
      'En proceso',
      '__keep__',
      'Postergado',
      $evidencias,
      $documentos
    );
    if (!$histSaved) {
      return ['ok' => '0', 'message' => 'No se pudo guardar el historial de postergacion.'];
    }

    $currentSeg = isset($ticket['seguimientos']) ? (int) $ticket['seguimientos'] : 0;
    $ticketUpdate = [
      'seguimientos' => (string) ($currentSeg + 1),
      'tuvo_seguimiento' => 'Si',
      'fecha_seguimiento' => $nowTs,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
      'id_encargado_seguimiento' => $employeeId,
      'estado' => 'En proceso',
      'estado_administrativo' => 'Postergado',
      'estado_admin_ticket' => 'Postergado',
      'estado_admin' => 'Postergado',
    ];
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (!empty($ticketUpdate)) {
      $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    }

    $segSaved = $this->insertSeguimientoTicket(
      $seguimientoTable,
      $ticket,
      $ticketPk,
      $histObservacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql,
      $evidenciaUrl
    );

    $sent = $this->notifySeguimiento($ticket, $histObservacion, $userName, $notifyTargets);
    return [
      'ok' => '1',
      'message' => 'Ticket postergado.' . ($sent > 0 ? ' Correos programados en cola: ' . $sent . '.' : ' Sin correos programados.'),
      'seg_saved' => $segSaved ? '1' : '0',
      'hist_saved' => '1',
      'emails_sent' => (string)$sent,
    ];
  }

  /**
   * @return array<string,string>
   */
  public function activateTicket(int $ticketPk, string $motivo): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $seguimientoTable = $this->db->table('jet_cct_seguimiento_ticket');
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($ticketPk <= 0 || !$this->schema->tableExists($ticketsTable) || !$this->schema->tableExists($histTable)) {
      return ['ok' => '0', 'message' => 'No se pudo preparar la activacion.'];
    }

    $ticket = $this->db->getRow("SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = \SCM\Core\Auth::userId();
    $userName = \SCM\Core\Auth::user() ?: ($userId > 0 ? ('Usuario #' . $userId) : 'Sistema');
    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);
    $histObservacion = 'Ticket activado: ' . $motivo;

    $histSaved = $this->insertHistorial(
      $histTable,
      $ticketPk,
      $histObservacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql,
      'En proceso',
      '__keep__',
      'Nuevo'
    );
    if (!$histSaved) {
      return ['ok' => '0', 'message' => 'No se pudo guardar el historial de activacion.'];
    }

    $currentSeg = isset($ticket['seguimientos']) ? (int) $ticket['seguimientos'] : 0;
    $ticketUpdate = [
      'seguimientos' => (string) ($currentSeg + 1),
      'tuvo_seguimiento' => 'Si',
      'fecha_seguimiento' => $nowTs,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
      'id_encargado_seguimiento' => $employeeId,
      'estado' => 'En proceso',
      'estado_administrativo' => 'Nuevo',
      'estado_admin_ticket' => 'Nuevo',
      'estado_admin' => 'Nuevo',
    ];
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (!empty($ticketUpdate)) {
      $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    }

    $segSaved = $this->insertSeguimientoTicket(
      $seguimientoTable,
      $ticket,
      $ticketPk,
      $histObservacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql
    );

    return [
      'ok' => '1',
      'message' => 'Ticket activado.',
      'seg_saved' => $segSaved ? '1' : '0',
      'hist_saved' => '1',
      'emails_sent' => '0',
    ];
  }

  /**
   * Inserta un registro en el historial del caso (jet_cct_historial_del_ticket).
   * Se usa para registrar acciones automáticas como un traslado de caso.
   */
  public function addSeguimientoEntry(array $ticket, int $ticketPk, string $observacion): bool
  {
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    $nowTs    = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId   = \SCM\Core\Auth::userId();
    $userName = \SCM\Core\Auth::user();
    if ($userName === '') {
      $userName = $userId > 0 ? ('Usuario #' . $userId) : 'Sistema';
    }
    $userInfo   = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);

    return $this->insertHistorial(
      $histTable,
      $ticketPk,
      $observacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql,
      'Trasladado',
      '__keep__',
      'Trasladado'
    );
  }

  private function insertSeguimientoTicket(string $seguimientoTable, array $ticket, int $ticketPk, string $observacion, int $userId, string $employeeId, string $userName, int $nowTs, string $nowMysql, string $evidenciaUrl = ''): bool
  {
    if (!$this->schema->tableExists($seguimientoTable)) {
      return false;
    }

    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticketPk]),
      'fecha' => $nowTs,
      'nombre' => $userName,
      'observacion' => $observacion,
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_coordinador' => $employeeId,
      'id_empleado' => $this->ticketEmployeeId($ticket, $employeeId),
    ];
    if ($evidenciaUrl !== '') {
      $payload['evidencia'] = $evidenciaUrl;
    }

    $payload = $this->schema->filterTableData($seguimientoTable, $payload);
    if (empty($payload)) {
      return false;
    }

    return $this->db->insert($seguimientoTable, $payload);
  }

  private function shouldSaveCotizacionResponse(string $estadoCotizacion): bool
  {
    return in_array(trim($estadoCotizacion), ['Aprobada', 'Desaprobada'], true);
  }

  /**
   * @return array<string,string>
   */
  public function saveCotizacionResponse(int $ticketPk, string $estado, string $observacion, string $motivo, string $financiacion, array $notifyTargets = []): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if ($ticketPk <= 0 || !$this->schema->tableExists($ticketsTable) || !$this->schema->tableExists($cotTable)) {
      return ['ok' => '0', 'message' => 'No se pudo preparar la respuesta de cotizacion.'];
    }
    $ticket = $this->db->getRow("SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }
    $cotIds = $this->splitIds((string)($ticket['id_cotizacion_mantenimiento'] ?? ''));
    if (empty($cotIds)) {
      return ['ok' => '0', 'message' => 'Este ticket no tiene cotizacion asociada.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = \SCM\Core\Auth::userId();
    $userName = \SCM\Core\Auth::user() ?: ($userId > 0 ? ('Usuario #' . $userId) : 'Sistema');
    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);
    $ticketEmployeeId = $this->ticketEmployeeId($ticket, $employeeId);
    $cotUpdate = [
      'estado' => $estado,
      'estado_respuesta_cotizacion_mantenimiento' => $estado,
      'fecha_respuesta' => $nowTs,
      'observacion_respuesta' => $observacion,
      'motivo' => $motivo,
      'financiacion' => $financiacion,
      'tuvo_seguimiento' => 'Si',
      'fecha_seguimiento' => $nowTs,
      'cct_modified' => $nowMysql,
      'cct_author_id' => $employeeId,
      'id_empleado' => $ticketEmployeeId,
      'id_coordinador' => $employeeId,
    ];
    $cotUpdate = $this->schema->filterTableData($cotTable, $cotUpdate);
    $updated = $this->updateCotizacionesByIds($cotTable, $cotIds, $cotUpdate);

    $ticketUpdate = [
      'estado_respuesta_cotizacion_mantenimiento' => $estado,
      'fecha_respuesta_cotizacion_mantenimiento' => $nowTs,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
      'id_encargado_seguimiento' => $employeeId,
    ];
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (!empty($ticketUpdate)) {
      $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    }

    $cotRow = $this->fetchCotizacion($cotTable, $cotIds[0]);
    $this->insertHistorialInmuebleCotizacion($ticket, $estado, $observacion, $motivo, $nowTs, $nowMysql);
    $sent = $this->notifyCotizacionResponse($ticket, is_array($cotRow) ? $cotRow : [], $estado, $observacion, $notifyTargets);
    return [
      'ok' => '1',
      'message' => 'Respuesta de cotizacion guardada.' . ($sent > 0 ? ' Correos programados en cola: ' . $sent . '.' : ' Sin correos programados.'),
      'cot_rows' => (string)$updated,
      'emails_sent' => (string)$sent,
    ];
  }

  /**
   * @return array<string,string>
   */
  public function closeTicket(int $ticketPk, string $motivo = ''): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $seguimientoTable = $this->db->table('jet_cct_seguimiento_ticket');
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($ticketPk <= 0 || !$this->schema->tableExists($ticketsTable)) {
      return ['ok' => '0', 'message' => 'No se pudo cerrar el ticket.'];
    }
    $motivo = trim($motivo);
    if ($motivo === '') {
      return ['ok' => '0', 'message' => 'El mensaje de cierre es obligatorio.'];
    }

    $ticket = $this->db->getRow("SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = \SCM\Core\Auth::userId();
    $userName = \SCM\Core\Auth::user() ?: ($userId > 0 ? ('Usuario #' . $userId) : 'Sistema');
    $userInfo = $this->findFuncionario($userId);
    $employeeId = $this->employeeLogicalId($userId, $userInfo);
    $histObservacion = 'Ticket cerrado: ' . $motivo;
    $ticketUpdate = [
      'estado' => 'Cerrado',
      'estado_administrativo' => 'Finalizado',
      'tuvo_seguimiento' => 'Si',
      'fecha_seguimiento' => $nowTs,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
      'id_encargado_seguimiento' => $employeeId,
    ];
    if (isset($ticket['seguimientos'])) {
      $ticketUpdate['seguimientos'] = (string) ((int) $ticket['seguimientos'] + 1);
    }
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (empty($ticketUpdate)) {
      return ['ok' => '0', 'message' => 'No hay columnas disponibles para cerrar el ticket.'];
    }

    $histSaved = false;
    if ($this->schema->tableExists($histTable)) {
      $histSaved = $this->insertHistorial(
        $histTable,
        $ticketPk,
        $histObservacion,
        $userId,
        $employeeId,
        $userName,
        $nowTs,
        $nowMysql,
        'Cerrado',
        '__keep__',
        'Finalizado'
      );
    }
    if (!$histSaved) {
      return ['ok' => '0', 'message' => 'No se pudo guardar el historial de cierre.'];
    }

    $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    $segSaved = $this->insertSeguimientoTicket(
      $seguimientoTable,
      $ticket,
      $ticketPk,
      $histObservacion,
      $userId,
      $employeeId,
      $userName,
      $nowTs,
      $nowMysql
    );
    return [
      'ok' => '1',
      'message' => 'Ticket cerrado y mensaje guardado.',
      'seg_saved' => $segSaved ? '1' : '0',
      'hist_saved' => '1',
    ];
  }

  /**
   * Envía (o encola) notificaciones de traslado de caso.
   *
   * @param array<string,mixed> $ticket
   * @param string[] $notifyTargets  destinatarios adicionales (solicitante, arrendatario, etc.)
   */
  public function notifyTrasladoCaso(
    array  $ticket,
    string $newEmpNombre,
    string $newEmpCorreo,
    string $oldEmpNombre,
    string $oldEmpCorreo,
    string $userName,
    array  $notifyTargets = [],
    bool   $notifyOldEmp  = true,
    bool   $notifyNewEmp  = true,
    string $newEmpCelular = '',
    string $newEmpId = ''
  ): int {
    $logicalTicket = $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject   = 'Traslado del caso — ticket #' . $logicalTicket;
    $sent      = 0;
    $fecha     = date('Y-m-d H:i');
    $botones   = EmailTemplate::buttons([['url' => $ticketUrl, 'label' => 'Ver ticket']]);

    $baseVars = [
      'id_ticket'        => EmailTemplate::e($logicalTicket),
      'empleado_anterior' => EmailTemplate::e($oldEmpNombre ?: 'N/A'),
      'empleado_nuevo'   => EmailTemplate::e($newEmpNombre ?: 'N/A'),
      'usuario'          => EmailTemplate::e($userName),
      'fecha'            => EmailTemplate::e($fecha),
      'botones'          => $botones,
    ];

    // Notificar empleado anterior
    if ($this->queue instanceof EmailQueue && $notifyOldEmp && $oldEmpCorreo !== '' && filter_var($oldEmpCorreo, FILTER_VALIDATE_EMAIL)) {
      $html = EmailTemplate::renderNamed('traslado_caso', array_merge($baseVars, [
        'destinatario'      => EmailTemplate::e($oldEmpNombre ?: 'funcionario'),
        'mensaje_principal' => 'Te informamos que el caso ha sido trasladado a otro funcionario.',
      ]));
      $sent += $this->sendMailToUnique($oldEmpCorreo, $subject, $html);
    }

    // Notificar empleado nuevo
    if ($this->queue instanceof EmailQueue && $notifyNewEmp && $newEmpCorreo !== '' && filter_var($newEmpCorreo, FILTER_VALIDATE_EMAIL)) {
      $html = EmailTemplate::renderNamed('traslado_caso', array_merge($baseVars, [
        'destinatario'      => EmailTemplate::e($newEmpNombre ?: 'funcionario'),
        'mensaje_principal' => 'Se te ha asignado un nuevo caso.',
      ]));
      $sent += $this->sendMailToUnique($newEmpCorreo, $subject, $html);
    }

    // Notificar destinatarios adicionales seleccionados
    $targets = $this->normalizeTargets($notifyTargets);
    if ($this->queue instanceof EmailQueue && !in_array('none', $targets, true) && !empty($targets)) {
      $ticketWithNewEmp = array_merge($ticket, [
        'id_empleado'      => $ticket['id_empleado'] ?? '',
        'correo_empleado'  => $newEmpCorreo,
        'nombre_empleado'  => $newEmpNombre,
        'empleado'         => $newEmpNombre,
      ]);
      foreach ($this->emailRecipientsForTargets($ticketWithNewEmp, $targets, [$oldEmpCorreo, $newEmpCorreo]) as $recipient) {
        $html = EmailTemplate::renderNamed('traslado_caso', array_merge($baseVars, [
          'destinatario'      => EmailTemplate::e($recipient['name'] ?: 'cliente'),
          'mensaje_principal' => 'Te informamos que el caso ha sido trasladado a un nuevo funcionario.',
        ]));
        $sent += $this->sendMailToUnique($recipient['email'], $subject, $html);
      }
    }

    if ($notifyNewEmp && trim($newEmpCelular) !== '') {
      try {
        $tipo = $this->firstNonEmpty([
          $ticket['tipo_pqrs'] ?? '',
          $ticket['tema_ayuda'] ?? '',
          $ticket['asunto'] ?? '',
          'Caso',
        ]);
        $destinationName = $newEmpNombre !== '' ? $newEmpNombre : 'Funcionario';
        $previousName = $oldEmpNombre !== '' ? $oldEmpNombre : 'Sin asignar';
        $message = "Hola {$destinationName}.\n\n";
        $message .= "La solicitud #{$logicalTicket} fue trasladada a tu gestión.\n\n";
        $message .= "*Tipo:* {$tipo}\n";
        $message .= "*Responsable anterior:* {$previousName}\n\n";
        $message .= "Consulta los detalles en el botón.";

        $smsQueue = new \SCM\Support\SmsQueue($this->db);
        $ok = $smsQueue->enqueue($newEmpCelular, $destinationName, $message, [
          'source_module' => 'case_transfer_funcionario',
          'campaign_tag' => 'pqr_trasladada_funcionario',
          'categoria_mensaje' => 'informacion',
          'id_funcionario' => is_numeric($newEmpId) ? (int) $newEmpId : 0,
          'id_ticket' => $logicalTicket,
          'tipo_solicitud' => $tipo,
          'responsable_anterior' => $previousName,
          'dedupe_key' => 'pqr_trasladada_funcionario:' . $logicalTicket . ':' . ($newEmpId !== '' ? $newEmpId : $destinationName),
          'template_name' => 'pqr_trasladada_funcionario_v1',
          'template_language' => 'es_CO',
          'template_components' => [
            [
              'type' => 'body',
              'parameters' => [
                ['type' => 'text', 'text' => $destinationName],
                ['type' => 'text', 'text' => $logicalTicket],
                ['type' => 'text', 'text' => $tipo],
                ['type' => 'text', 'text' => $previousName],
              ],
            ],
            [
              'type' => 'button',
              'sub_type' => 'url',
              'index' => '0',
              'parameters' => [
                ['type' => 'text', 'text' => $logicalTicket],
              ],
            ],
          ],
        ]);
        if (!$ok) {
          $detail = trim($smsQueue->lastError());
          error_log('control-servicios-inmobiliarios: no se pudo encolar WhatsApp de traslado del caso #' . $logicalTicket . ($detail !== '' ? ': ' . $detail : ''));
        }
      } catch (\Throwable $exception) {
        error_log('control-servicios-inmobiliarios: error preparando WhatsApp de traslado del caso #' . $logicalTicket . ': ' . $exception->getMessage());
      }
    }

    return $sent;
  }

  /**
   * @return array<string,string>
   */
}
