<?php

namespace SCM\Modules\ServiciosInmobiliarios;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

final class SeguimientoService
{
  private Database $db;
  private SchemaInspector $schema;
  private ?EmailQueue $queue = null;

  public function __construct(Database $db, SchemaInspector $schema)
  {
    $this->db     = $db;
    $this->schema = $schema;
  }

  public function setQueue(EmailQueue $queue): void
  {
    $this->queue = $queue;
  }

  /**
   * @return array<string,string>
   */
  public function save(int $ticketPk, string $observacion, string $estadoTicket, string $estadoCotizacion, string $estadoAdministrativo, bool $cerrarTicket, array $notifyTargets = [], $evidencias = '', array $documentos = []): array
  {
    $ticketsTable      = $this->db->table('jet_cct_tickets');
    $seguimientoTable  = $this->db->table('jet_cct_seguimiento_ticket');
    $histFallbackTable = $this->db->table('jet_cct_historial_del_ticket');
    $estadoCotizacion = '__keep__';

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

    return [
      'ok' => '1',
      'message' => $msg,
      'seg_saved' => $segSaved ? '1' : '0',
      'hist_saved' => $histSaved ? '1' : '0',
      'cot_rows' => '0',
      'emails_sent' => (string)$emailsSent,
    ];
  }

  /**
   * @return array<string,string>
   */
  public function saveTicketResponse(int $ticketPk, string $respuesta, string $estadoAdministrativo, bool $cerrarTicket, array $notifyTargets = [], $imagenes = '', array $documentos = []): array
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
    return [
      'ok' => '1',
      'message' => 'Respuesta guardada.' . ($sent > 0 ? ' Correos programados en cola: ' . $sent . '.' : ' Sin correos programados.'),
      'emails_sent' => (string)$sent,
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
  public function closeTicket(int $ticketPk): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if ($ticketPk <= 0 || !$this->schema->tableExists($ticketsTable)) {
      return ['ok' => '0', 'message' => 'No se pudo cerrar el ticket.'];
    }

    $ticket = $this->db->getRow("SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    if (!is_array($ticket)) {
      return ['ok' => '0', 'message' => 'Ticket no encontrado.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $ticketUpdate = [
      'estado' => 'Cerrado',
      'estado_administrativo' => 'Finalizado',
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
    ];
    $ticketUpdate = $this->schema->filterTableData($ticketsTable, $ticketUpdate);
    if (empty($ticketUpdate)) {
      return ['ok' => '0', 'message' => 'No hay columnas disponibles para cerrar el ticket.'];
    }

    $this->db->update($ticketsTable, $ticketUpdate, ['_ID' => $ticketPk]);
    return ['ok' => '1', 'message' => 'Ticket cerrado y estado administrativo finalizado.'];
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
    bool   $notifyNewEmp  = true
  ): int {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }

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
    if ($notifyOldEmp && $oldEmpCorreo !== '' && filter_var($oldEmpCorreo, FILTER_VALIDATE_EMAIL)) {
      $html = EmailTemplate::renderNamed('traslado_caso', array_merge($baseVars, [
        'destinatario'      => EmailTemplate::e($oldEmpNombre ?: 'funcionario'),
        'mensaje_principal' => 'Te informamos que el caso ha sido trasladado a otro funcionario.',
      ]));
      $sent += $this->sendMailToUnique($oldEmpCorreo, $subject, $html);
    }

    // Notificar empleado nuevo
    if ($notifyNewEmp && $newEmpCorreo !== '' && filter_var($newEmpCorreo, FILTER_VALIDATE_EMAIL)) {
      $html = EmailTemplate::renderNamed('traslado_caso', array_merge($baseVars, [
        'destinatario'      => EmailTemplate::e($newEmpNombre ?: 'funcionario'),
        'mensaje_principal' => 'Se te ha asignado un nuevo caso.',
      ]));
      $sent += $this->sendMailToUnique($newEmpCorreo, $subject, $html);
    }

    // Notificar destinatarios adicionales seleccionados
    $targets = $this->normalizeTargets($notifyTargets);
    if (!in_array('none', $targets, true) && !empty($targets)) {
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

    return $sent;
  }

  /**
   * @return array<string,string>
   */
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

  private function notifyTicketResponse(array $ticket, string $logicalTicket, string $respuesta, string $userName, string $estado, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = $estado === 'Cerrado' ? 'Ticket #' . $logicalTicket . ' cerrado' : 'Ticket #' . $logicalTicket . ' con nueva respuesta';
    $sent = 0;
    foreach ($this->emailRecipientsForTargets($ticket, $notifyTargets) as $recipient) {
      $html = EmailTemplate::renderNamed('respuesta_ticket', [
        'asunto_correo' => EmailTemplate::e($subject),
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
        'mensaje_intro' => $estado === 'Cerrado'
          ? 'Te informamos que el ticket ha sido cerrado.'
          : 'Te informamos que el ticket ha tenido una nueva respuesta.',
        'respuesta' => wp_kses_post($respuesta),
        'usuario' => EmailTemplate::e($userName),
        'botones' => EmailTemplate::buttons([['url' => $ticketUrl, 'label' => 'Ver ticket']]),
      ]);
      $sent += $this->sendMailToUnique($recipient['email'], $subject, $html);
    }
    return $sent;
  }

  private function notifyCotizacionResponse(array $ticket, array $cotizacion, string $estado, string $observacion, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $cotId = $this->firstNonEmpty([$cotizacion['_ID'] ?? '', $ticket['id_cotizacion_mantenimiento'] ?? '']);
    $logicalTicket = $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $cotUrl = $cotId !== '' ? 'https://sucasainmobiliaria.com.co/cotizacion-de-mantenimiento/?numero=' . rawurlencode($cotId) : '';
    $subject = 'Nueva respuesta de cotizacion de mantenimiento #' . $cotId;
    $motivo = trim((string)($cotizacion['motivo'] ?? ''));
    $recipients = $this->emailRecipientsForTargets($ticket, $notifyTargets);
    if (!in_array('none', $this->normalizeTargets($notifyTargets), true)) {
      foreach (
        [
          (string)($cotizacion['email_destinatario'] ?? ''),
          (string)($cotizacion['email_creador'] ?? ''),
          (string)($cotizacion['email_coordinador'] ?? ''),
        ] as $extraEmail
      ) {
        $extraEmail = trim($extraEmail);
        if ($extraEmail !== '') {
          $recipients[] = ['email' => $extraEmail, 'name' => 'cliente', 'role' => 'cotizacion'];
        }
      }
    }

    $sent = 0;
    foreach ($this->uniqueEmailRecipients($recipients) as $recipient) {
      $html = EmailTemplate::renderNamed('respuesta_cotizacion', [
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
        'id_cotizacion' => EmailTemplate::e($cotId),
        'id_ticket' => EmailTemplate::e($logicalTicket),
        'estado' => EmailTemplate::e($estado),
        'observacion' => wp_kses_post($observacion),
        'motivo_bloque' => $estado === 'Desaprobada' && $motivo !== ''
          ? '<p style="font-weight:500;margin:10px 0;"><b>Motivo:</b> ' . EmailTemplate::e($motivo) . '</p>'
          : '',
        'financiacion' => EmailTemplate::e((string)($cotizacion['financiacion'] ?? '')),
        'botones' => EmailTemplate::buttons([['url' => $cotUrl, 'label' => 'Ver cotizacion']]),
      ]);
      $sent += $this->sendMailToUnique($recipient['email'], $subject, $html, [
        'cc' => [],
      ]);
    }

    return $sent;
  }

  private function notifySeguimiento(array $ticket, string $observacion, string $userName, array $notifyTargets = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $logicalTicket = $this->firstNonEmpty([$ticket['id_ticket'] ?? '', $ticket['_ID'] ?? '']);
    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = 'Nuevo seguimiento del ticket #' . $logicalTicket;
    $sent = 0;
    foreach ($this->emailRecipientsForTargets($ticket, $notifyTargets) as $recipient) {
      $html = EmailTemplate::renderNamed('nuevo_seguimiento', [
        'destinatario' => EmailTemplate::e($recipient['name'] !== '' ? $recipient['name'] : 'cliente'),
        'id_ticket' => EmailTemplate::e($logicalTicket),
        'observacion' => wp_kses_post($observacion),
        'usuario' => EmailTemplate::e($userName),
        'fecha' => EmailTemplate::e(date('Y-m-d H:i')),
        'botones' => EmailTemplate::buttons([['url' => $ticketUrl, 'label' => 'Ver ticket']]),
      ]);
      $sent += $this->sendMailToUnique($recipient['email'], $subject, $html, [
        'cc' => [],
      ]);
    }
    return $sent;
  }

  /** @param array<string,mixed> $ticket @return string[] */
  private function ticketParticipantEmails(array $ticket, bool $includeEmployee = false): array
  {
    $emails = [
      (string)($ticket['correo_solicitante'] ?? ''),
      (string)($ticket['correo_arrendatario'] ?? ''),
      (string)($ticket['correo_propietario'] ?? ''),
    ];
    if ($includeEmployee) {
      $employeeEmail = (string)($ticket['correo_empleado'] ?? '');
      if ($employeeEmail === '') {
        $func = $this->findFuncionarioByEmployeeId($ticket['id_empleado'] ?? '');
        $employeeEmail = (string)($func['correo'] ?? '');
      }
      $emails[] = $employeeEmail;
    }
    return $this->normalizeEmails($emails);
  }

  /** @param array<string,mixed> $ticket @param string[] $targets @param string[] $exclude @return string[] */
  private function emailsForTargets(array $ticket, array $targets = [], array $exclude = []): array
  {
    return array_map(static function (array $recipient): string {
      return $recipient['email'];
    }, $this->emailRecipientsForTargets($ticket, $targets, $exclude));
  }

  /** @param array<string,mixed> $ticket @param string[] $targets @param string[] $exclude @return array<int,array{email:string,name:string,role:string}> */
  private function emailRecipientsForTargets(array $ticket, array $targets = [], array $exclude = []): array
  {
    $targets = $this->normalizeTargets($targets);
    if (in_array('none', $targets, true)) {
      return [];
    }
    if (empty($targets)) {
      $targets = ['solicitante', 'arrendatario', 'propietario', 'empleado', 'admin'];
    }

    $excludeMap = array_fill_keys($exclude, true);
    $recipients = [];
    $add = static function (string $role, string $name, string $email) use (&$recipients, $targets, $excludeMap): void {
      if (!in_array($role, $targets, true) || !empty($excludeMap[$role])) {
        return;
      }
      $email = trim($email);
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
      }
      $recipients[] = ['email' => $email, 'name' => trim($name), 'role' => $role];
    };

    $add('solicitante', (string)($ticket['solicitante'] ?? ''), (string)($ticket['correo_solicitante'] ?? ''));
    $add('arrendatario', (string)($ticket['arrendatario'] ?? ''), (string)($ticket['correo_arrendatario'] ?? ''));
    $add('propietario', (string)($ticket['propietario'] ?? ''), (string)($ticket['correo_propietario'] ?? ''));

    $employeeEmail = (string)($ticket['correo_empleado'] ?? '');
    $employeeName = (string)($ticket['nombre_empleado'] ?? $ticket['empleado'] ?? '');
    if ($employeeEmail === '') {
      $func = $this->findFuncionarioByEmployeeId($ticket['id_empleado'] ?? '');
      $employeeEmail = (string)($func['correo'] ?? '');
      if ($employeeName === '') {
        $employeeName = (string)($func['nombre'] ?? '');
      }
    }
    $add('empleado', $employeeName, $employeeEmail);

    if (in_array('admin', $targets, true) && empty($excludeMap['admin'])) {
      foreach ($this->adminNotificationEmails() as $adminEmail) {
        $recipients[] = ['email' => $adminEmail, 'name' => 'equipo administrativo', 'role' => 'admin'];
      }
    }

    return $this->uniqueEmailRecipients($recipients);
  }

  /** @param array<int,array{email:string,name:string,role:string}> $recipients @return array<int,array{email:string,name:string,role:string}> */
  private function uniqueEmailRecipients(array $recipients): array
  {
    $seen = [];
    $out = [];
    foreach ($recipients as $recipient) {
      $email = strtolower(trim((string)($recipient['email'] ?? '')));
      if ($email === '' || isset($seen[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $seen[$email] = true;
      $out[] = [
        'email' => $email,
        'name' => trim((string)($recipient['name'] ?? '')),
        'role' => trim((string)($recipient['role'] ?? '')),
      ];
    }
    return $out;
  }

  /** @param string[] $targets */
  private function targetSelected(array $targets, string $target): bool
  {
    $targets = $this->normalizeTargets($targets);
    if (in_array('none', $targets, true)) {
      return false;
    }
    return empty($targets) || in_array($target, $targets, true);
  }

  /** @param string[] $targets @return string[] */
  private function normalizeTargets(array $targets): array
  {
    $allowed = ['solicitante', 'arrendatario', 'propietario', 'empleado', 'admin', 'none'];
    $out = [];
    foreach ($targets as $target) {
      $target = strtolower(trim((string)$target));
      if (in_array($target, $allowed, true)) {
        $out[$target] = true;
      }
    }
    return array_keys($out);
  }

  /** @return string[] */
  private function adminNotificationEmails(): array
  {
    return $this->normalizeEmails([
      'sucasacorreos@gmail.com',
      'gcorrearivera@gmail.com',
      'sucasa.inmobiliaria@hotmail.com',
    ]);
  }

  /** @param array<int,mixed> $items @return string[] */
  private function normalizeEmails(array $items): array
  {
    $out = [];
    foreach ($items as $item) {
      $email = trim((string)$item);
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $out[strtolower($email)] = $email;
    }
    return array_values($out);
  }

  /** @param array<int,mixed>|string $recipients @param array<string,mixed> $options */
  private function sendMailToUnique($recipients, string $subject, string $html, array $options = []): int
  {
    if (!$this->queue instanceof EmailQueue) {
      return 0;
    }
    $to = is_array($recipients) ? $this->normalizeEmails($recipients) : $this->normalizeEmails([$recipients]);
    if (empty($to)) {
      $cc = isset($options['cc']) && is_array($options['cc']) ? $this->normalizeEmails($options['cc']) : [];
      if (empty($cc)) {
        return 0;
      }
      $to = $cc;
      $options['cc'] = [];
    } elseif (isset($options['cc']) && is_array($options['cc'])) {
      $toKeys = array_fill_keys(array_map('strtolower', $to), true);
      $options['cc'] = array_values(array_filter($this->normalizeEmails($options['cc']), static function (string $email) use ($toKeys): bool {
        return !isset($toKeys[strtolower($email)]);
      }));
    }

    // Modo cola: encolar para procesamiento asíncrono
    if ($this->queue instanceof EmailQueue) {
      $allEmails = array_merge($to, isset($options['cc']) && is_array($options['cc']) ? $options['cc'] : []);
      $this->queue->enqueue($allEmails, $subject, $html);
      return count($allEmails);
    }

    return 0;
  }

  /** @param array<int,mixed> $values */
  private function firstNonEmpty(array $values): string
  {
    foreach ($values as $value) {
      $text = trim((string)$value);
      if ($text !== '') {
        return $text;
      }
    }
    return '';
  }

  /** @return string[] */
  private function splitIds(string $raw): array
  {
    $text = trim($raw);
    if ($text === '') {
      return [];
    }

    $parts = preg_split('/[,\s;|]+/', $text) ?: [];
    $ids = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        $ids[$id] = true;
      }
    }

    return array_keys($ids);
  }
}
