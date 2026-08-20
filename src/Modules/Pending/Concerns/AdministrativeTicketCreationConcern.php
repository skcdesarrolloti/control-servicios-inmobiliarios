<?php

declare(strict_types=1);

namespace SCM\Modules\Pending\Concerns;

use SCM\Core\Auth;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

trait AdministrativeTicketCreationConcern
{
  public function createAdministrativeTicket(array $input, array $imagenes = [], array $documentos = [], array $notifyRecipients = []): array
  {
    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $ticketsTable = $db->table('jet_cct_tickets');
    if (!$schema->tableExists($ticketsTable)) {
      return ['ok' => '0', 'message' => 'No existe la tabla de tickets.'];
    }

    $contractRef = trim((string) ($input['contract_pk'] ?? $input['id_contrato'] ?? ''));
    $contract = $this->repo->getContratoArrendamientoById($contractRef);
    if (!is_array($contract)) {
      return ['ok' => '0', 'message' => 'Contrato no encontrado.'];
    }

    $mode = strtolower(trim((string) ($input['ticket_mode'] ?? 'administrativo')));
    if (!in_array($mode, ['administrativo', 'preventiva'], true)) {
      $mode = 'administrativo';
    }

    $idEmpleado = trim((string) ($input['id_empleado'] ?? ''));
    if ($idEmpleado === '') {
      return ['ok' => '0', 'message' => 'Selecciona un funcionario responsable.'];
    }
    $funcionario = $this->repo->getFuncionarioById($idEmpleado) ?: [];

    $tema = $this->cleanText($input['tema_ayuda'] ?? ($mode === 'preventiva' ? 'Revision preventiva' : ''));
    $departamento = $this->cleanText($input['departamento'] ?? ($mode === 'preventiva' ? 'Servicio al arrendatario' : ''));
    $prioridad = $this->cleanText($input['prioridad'] ?? ($mode === 'preventiva' ? 'Prioridad urgente' : ''));
    $asunto = $this->cleanText($input['asunto'] ?? ($mode === 'preventiva' ? 'REVISION PREVENTIVA' : ''));
    $descripcion = trim(wp_kses_post((string) ($input['descripcion'] ?? '')));
    $departamentosPermitidos = [
      'Servicio al cliente',
      'Servicio al propietario',
      'Servicio al arrendatario',
      'Servicio a la copropiedad',
    ];

    if ($mode === 'preventiva' && $descripcion === '') {
      $descripcion = 'Se crea este ticket para coordinar y documentar la revisión preventiva anual del inmueble conforme a la fecha de inicio del contrato de arrendamiento.';
    }
    if ($tema === '' || $departamento === '' || $prioridad === '' || $asunto === '' || $descripcion === '') {
      return ['ok' => '0', 'message' => 'Completa responsable, prioridad, departamento, tema, asunto y descripcion.'];
    }
    if (!in_array($departamento, $departamentosPermitidos, true)) {
      return ['ok' => '0', 'message' => 'Selecciona un departamento valido.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $userId = Auth::userId();
    $userName = Auth::user();
    if ($userName === '') {
      $userName = $userId > 0 ? ('Usuario #' . $userId) : 'Sistema';
    }

    $contractPk = trim((string) ($contract['_ID'] ?? $contractRef));
    $contractCode = $this->firstNonEmpty([$input['contrato'] ?? '', $contract['contrato'] ?? '', $contractPk]);
    $propertyId = $this->firstNonEmpty([$input['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '', $contract['inmueble'] ?? '']);
    $propertyCode = $this->firstNonEmpty([$input['inmueble'] ?? '', $contract['inmueble'] ?? '', $propertyId]);
    $ownerId = $this->firstNonEmpty([$input['id_propietario'] ?? '', $contract['id_propietario'] ?? '']);
    $tenantId = $this->firstNonEmpty([$input['id_arrendatario'] ?? '', $contract['id_arrendatario'] ?? '']);
    $ownerName = $this->firstNonEmpty([$input['propietario'] ?? '', $contract['propietario'] ?? '']);
    $tenantName = $this->firstNonEmpty([$input['arrendatario'] ?? '', $contract['arrendatario'] ?? '']);
    $ownerEmail = $this->firstNonEmpty([$input['correo_propietario'] ?? '', $contract['correo_propietario'] ?? '']);
    $tenantEmail = $this->firstNonEmpty([$input['correo_arrendatario'] ?? '', $contract['correo_arrendatario'] ?? '']);
    $ownerPhone = $this->firstNonEmpty([$input['celular_propietario'] ?? '', $contract['celular_propietario'] ?? '']);
    $tenantPhone = $this->firstNonEmpty([$input['celular_arrendatario'] ?? '', $contract['celular_arrendatario'] ?? '']);
    $solicitanteTipo = $mode === 'preventiva'
      ? 'arrendatario'
      : strtolower(trim((string) ($input['solicitante_tipo'] ?? 'propietario')));
    $solicitanteEsArr = $solicitanteTipo === 'arrendatario';
    $solicitanteId = $solicitanteEsArr ? $tenantId : $ownerId;
    $solicitanteName = $solicitanteEsArr ? $tenantName : $ownerName;
    $solicitanteEmail = $solicitanteEsArr ? $tenantEmail : $ownerEmail;
    $solicitantePhone = $solicitanteEsArr ? $tenantPhone : $ownerPhone;
    $idInventario = $this->firstNonEmpty([$input['id_inventario'] ?? '', $contract['id_inventario'] ?? '']);
    $employeeName = $this->firstNonEmpty([$funcionario['nombre'] ?? '', $funcionario['empleado'] ?? '', $funcionario['nombre_empleado'] ?? '', $idEmpleado]);
    $employeeEmail = $this->firstNonEmpty([$funcionario['correo'] ?? '', $funcionario['correo_empleado'] ?? '', $funcionario['email'] ?? '']);
    $employeePhone = $this->firstNonEmpty([$funcionario['celular'] ?? '', $funcionario['telefono'] ?? '']);
    $sucursalId = $this->firstNonEmpty([$input['id_sucursal'] ?? '', $contract['id_sucursal'] ?? '', $contract['sucursal'] ?? '']);
    $sucursal = $this->repo->getSucursalById($sucursalId) ?: [];
    $contractualName = $this->firstNonEmpty([$sucursal['nombre_contractual'] ?? '', $sucursal['nombre'] ?? '']);
    $contractualPhone = $this->firstNonEmpty([$sucursal['celular_contractual'] ?? '', $sucursal['telefono'] ?? '']);
    $contractualEmail = $this->firstNonEmpty([$sucursal['correo_contractual'] ?? '', $sucursal['correo'] ?? '']);
    $ciudad = $this->firstNonEmpty([$sucursal['ciudad'] ?? '', $contract['ciudad'] ?? '', 'Cartagena de Indias']);

    $ticketPayload = [
      'cct_status' => 'publish',
      'cct_author_id' => $userId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'estado' => 'Nuevo',
      'estado_administrativo' => 'Nuevo',
      'tuvo_seguimiento' => 'No',
      'tuvo_reporte' => 'No',
      'estado_rev_entrega' => 'No',
      'estado_acta_entrega' => 'No',
      'estado_acta_desocupacion' => 'No',
      'estado_rev_correctiva' => 'No',
      'estado_rev_preventiva' => 'No',
      'estado_cotizacion_mantenimiento' => 'No',
      'estado_acta_satisfaccion' => 'No',
      'estado_rev_recibo' => 'No',
      'estado_acta_recibo' => 'No',
      'creador_por' => 'Funcionario',
      'id_creador' => $userId,
      'fecha' => $nowTs,
      'fecha_actualizacion' => $nowTs,
      'id_contrato' => $contractPk,
      'contrato' => $contractCode,
      'id_inmueble' => $propertyId,
      'inmueble' => $propertyCode,
      'direccion' => $this->firstNonEmpty([$input['direccion'] ?? '', $contract['direccion'] ?? '']),
      'barrio' => $this->firstNonEmpty([$input['barrio'] ?? '', $contract['barrio'] ?? '']),
      'id_arrendatario' => $tenantId,
      'arrendatario' => $tenantName,
      'correo_arrendatario' => $tenantEmail,
      'celular_arrendatario' => $tenantPhone,
      'id_propietario' => $ownerId,
      'propietario' => $ownerName,
      'correo_propietario' => $ownerEmail,
      'celular_propietario' => $ownerPhone,
      'id_solicitante' => $solicitanteId,
      'solicitante_tipo' => $solicitanteTipo,
      'solicitante' => $solicitanteName,
      'correo_solicitante' => $solicitanteEmail,
      'celular_solicitante' => $solicitantePhone,
      'id_empleado' => $idEmpleado,
      'id_asignado' => $idEmpleado,
      'empleado' => $employeeName,
      'nombre_empleado' => $employeeName,
      'correo_empleado' => $employeeEmail,
      'celular_empleado' => $employeePhone,
      'prioridad' => $prioridad,
      'departamento' => $departamento,
      'tema_ayuda' => $tema,
      'asunto' => $asunto,
      'descripcion' => $descripcion,
      'sucursal' => $sucursalId,
      'ciudad' => $ciudad,
      'nombre_contractual' => $contractualName,
      'celular_contractual' => $contractualPhone,
      'correo_contractual' => $contractualEmail,
      'id_inventario' => $idInventario,
      'estado_inventario' => trim($idInventario) !== '' && trim($idInventario) !== '0' ? 'Si' : 'No',
      'fecha_terminacion_contrato' => $this->firstNonEmpty([$input['fecha_final_contrato'] ?? '', $contract['fin_contrato'] ?? '']),
      'registro_fotografico' => $this->firstNonEmpty([$input['registro_fotografico'] ?? '', $contract['registro_fotografico'] ?? '']),
    ];

    if (!empty($imagenes)) {
      $ticketPayload['imagenes'] = count($imagenes) === 1 ? $imagenes[0] : serialize(array_values($imagenes));
      $ticketPayload['imagen'] = $imagenes[0] ?? '';
    }
    if (!empty($documentos)) {
      $ticketPayload['archivos'] = serialize(array_values($documentos));
    }

    $insertPayload = $schema->filterTableData($ticketsTable, $ticketPayload);
    if (empty($insertPayload)) {
      return ['ok' => '0', 'message' => 'No se pudo preparar la informacion del ticket.'];
    }

    $pdo = $db->pdo();
    $startedTransaction = false;
    $ticketId = 0;
    $warnings = [];
    $generatedPdfs = [];
    try {
      if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
      }

      $db->insert($ticketsTable, $insertPayload);
      $ticketId = (int) $db->lastInsertId();
      if ($ticketId <= 0) {
        throw new \RuntimeException('No se pudo obtener el ID del ticket creado.');
      }

      if ($schema->columnExists($ticketsTable, 'id_ticket')) {
        $db->update($ticketsTable, ['id_ticket' => (string) $ticketId], ['_ID' => $ticketId]);
      }

      $generated = $this->generateTicketPdfs($mode, $tema, $ticketId, $ticketPayload);
      $generatedPdfs = $generated['pdfs'];
      $warnings = array_merge($warnings, $generated['warnings']);
      if (!empty($generatedPdfs)) {
        $this->applyGeneratedPdfsToPayload($ticketPayload, $documentos, $generatedPdfs);
        $updatePayload = $schema->filterTableData($ticketsTable, $this->ticketGeneratedPdfData($ticketPayload));
        if (!empty($updatePayload)) {
          $db->update($ticketsTable, $updatePayload, ['_ID' => $ticketId]);
        }
      }

      $this->insertTicketHistory($schema, $ticketId, $ticketPayload, $imagenes, $documentos, $userName, $idEmpleado, $nowTs, $nowMysql);
      $this->insertPropertyHistory($schema, $ticketId, $ticketPayload, $userName, $idEmpleado, $nowTs, $nowMysql, $mode);
      $this->updateContractAfterTicket($schema, $contract, $ticketId, $ticketPayload);
      if ($mode === 'preventiva' && function_exists('do_action')) {
        do_action('guardar-revision-preventiva', $ticketId, $ticketPayload, $contract);
      }

      if ($startedTransaction && $pdo->inTransaction()) {
        $pdo->commit();
      }
    } catch (\Throwable $e) {
      if ($startedTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      return ['ok' => '0', 'message' => 'No se pudo crear el ticket: ' . $e->getMessage()];
    }

    $queued = $this->queueCreationEmails($ticketId, $ticketPayload, $notifyRecipients, $mode, $generatedPdfs);
    $message = 'Ticket #' . $ticketId . ' creado correctamente.';
    if (!empty($warnings)) {
      $message .= ' ' . implode(' ', $warnings);
    }

    return [
      'ok' => '1',
      'message' => $message,
      'ticket_id' => (string) $ticketId,
      'ticket_url' => 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode((string) $ticketId),
      'emails_queued' => (string) $queued,
      'warnings' => $warnings,
    ];
  }

  /**
   * @param array<string,mixed> $ticketPayload
   * @return array{pdfs:array<string,array<string,string>>,warnings:array<int,string>}
   */
  private function generateTicketPdfs(string $mode, string $tema, int $ticketId, array $ticketPayload): array
  {
    try {
      $pdfs = (new TicketPdfGenerator())->generate($mode, $tema, $ticketId, $ticketPayload);
      return ['pdfs' => $pdfs, 'warnings' => []];
    } catch (\Throwable $e) {
      return [
        'pdfs' => [],
        'warnings' => ['No se pudieron generar los PDFs: ' . $e->getMessage()],
      ];
    }
  }

  /**
   * @param array<string,mixed> $ticketPayload
   * @param array<int,array{nombre_archivo:string,archivo:string}> $documentos
   * @param array<string,array<string,string>> $generatedPdfs
   */
  private function applyGeneratedPdfsToPayload(array &$ticketPayload, array &$documentos, array $generatedPdfs): void
  {
    foreach ($generatedPdfs as $key => $pdf) {
      $url = trim((string) ($pdf['url'] ?? ''));
      if ($url === '') {
        continue;
      }
      $ticketPayload[$key] = $url;
      if ($key === 'acta_revision_preventiva_arrendatario') {
        $ticketPayload['acta_preventiva_arrendatario'] = $url;
        $ticketPayload['pdf_revision_preventiva_arrendatario'] = $url;
      } elseif ($key === 'acta_revision_preventiva_propietario') {
        $ticketPayload['acta_preventiva_propietario'] = $url;
        $ticketPayload['pdf_revision_preventiva_propietario'] = $url;
      } elseif ($key === 'acta_desocupacion') {
        $ticketPayload['estado_acta_desocupacion'] = 'Si';
      }
      $documentos[] = [
        'nombre_archivo' => (string) ($pdf['title'] ?? $key),
        'archivo' => $url,
      ];
    }
    if (!empty($documentos)) {
      $ticketPayload['archivos'] = serialize(array_values($documentos));
    }
  }

  /**
   * @param array<string,mixed> $ticketPayload
   * @return array<string,mixed>
   */
  private function ticketGeneratedPdfData(array $ticketPayload): array
  {
    $keys = [
      'archivos',
      'acta_revision_preventiva_arrendatario',
      'acta_revision_preventiva_propietario',
      'acta_preventiva_arrendatario',
      'acta_preventiva_propietario',
      'pdf_revision_preventiva_arrendatario',
      'pdf_revision_preventiva_propietario',
      'acta_desocupacion',
      'estado_acta_desocupacion',
    ];
    $data = [];
    foreach ($keys as $key) {
      if (array_key_exists($key, $ticketPayload) && trim((string) $ticketPayload[$key]) !== '') {
        $data[$key] = $ticketPayload[$key];
      }
    }
    return $data;
  }

  /** @param mixed $value */
  private function cleanText($value): string
  {
    return trim(strip_tags(stripslashes((string) $value)));
  }

  /** @param array<int,mixed> $values */
  private function firstNonEmpty(array $values): string
  {
    foreach ($values as $value) {
      $text = trim((string) $value);
      if ($text !== '') {
        return $text;
      }
    }
    return '';
  }

  /** @param array<string,mixed> $ticketPayload */
  private function insertTicketHistory(SchemaInspector $schema, int $ticketId, array $ticketPayload, array $imagenes, array $documentos, string $userName, string $idEmpleado, int $nowTs, string $nowMysql): void
  {
    $db = $this->repo->getDb();
    $histTable = $db->table('jet_cct_historial_del_ticket');
    if (!$schema->tableExists($histTable)) {
      return;
    }

    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketId,
      'fecha' => $nowTs,
      'nombre' => $userName,
      'respuesta' => 'Ticket administrativo creado: ' . (string) ($ticketPayload['asunto'] ?? ''),
      'observacion' => 'Ticket administrativo creado: ' . (string) ($ticketPayload['asunto'] ?? ''),
      'cct_author_id' => $idEmpleado,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $idEmpleado,
      'fue_editada' => 'Si',
    ];
    if (!empty($imagenes)) {
      $payload['imagen'] = count($imagenes) === 1 ? $imagenes[0] : serialize(array_values($imagenes));
    }
    if (!empty($documentos)) {
      $payload['archivos'] = serialize(array_values($documentos));
    }
    $payload = $schema->filterTableData($histTable, $payload);
    if (!empty($payload)) {
      $db->insert($histTable, $payload);
    }
  }

  /** @param array<string,mixed> $ticketPayload */
  private function insertPropertyHistory(SchemaInspector $schema, int $ticketId, array $ticketPayload, string $userName, string $idEmpleado, int $nowTs, string $nowMysql, string $mode): void
  {
    $db = $this->repo->getDb();
    $histTable = $db->table('jet_cct_historial_del_inmueble');
    if (!$schema->tableExists($histTable)) {
      return;
    }

    $observacion = $mode === 'preventiva'
      ? 'Se ha creado un ticket para la realizacion de la revision preventiva del inmueble.'
      : 'Se ha creado un ticket administrativo.';
    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketId,
      'id_inmueble' => (string) ($ticketPayload['id_inmueble'] ?? ''),
      'id_inmueble_data' => (string) ($ticketPayload['id_inmueble'] ?? ''),
      'fecha' => $nowTs,
      'tipo_reporte' => 'Ticket',
      'tipo_de_reporte_his' => 'Ticket',
      'observacion' => $observacion,
      'observacion_his' => $observacion,
      'funcionario' => $userName,
      'reporte_realizado_por_his' => $userName,
      'id_empleado' => $idEmpleado,
      'cct_author_id' => $idEmpleado,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
    ];
    $payload = $schema->filterTableData($histTable, $payload);
    if (!empty($payload)) {
      $db->insert($histTable, $payload);
    }
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $ticketPayload */
  private function updateContractAfterTicket(SchemaInspector $schema, array $contract, int $ticketId, array $ticketPayload): void
  {
    $db = $this->repo->getDb();
    $contractTable = $db->table('jet_cct_contratos_arrendamiento');
    if (!$schema->tableExists($contractTable)) {
      return;
    }

    $tema = strtolower(trim((string) ($ticketPayload['tema_ayuda'] ?? '')));
    $payload = [
      'cct_modified' => date('Y-m-d H:i:s'),
    ];
    $barrio = trim((string) ($ticketPayload['barrio'] ?? ''));
    if ($barrio !== '') {
      $payload['barrio'] = $barrio;
    }
    $registroFotografico = trim((string) ($ticketPayload['registro_fotografico'] ?? ''));
    if ($registroFotografico !== '') {
      $payload['registro_fotografico'] = $registroFotografico;
    }
    if ($tema === 'entrega de inmuebles') {
      $payload['id_ticket_entrega'] = (string) $ticketId;
    }
    foreach ([
      'acta_revision_preventiva_arrendatario',
      'acta_revision_preventiva_propietario',
      'acta_preventiva_arrendatario',
      'acta_preventiva_propietario',
      'pdf_revision_preventiva_arrendatario',
      'pdf_revision_preventiva_propietario',
      'acta_desocupacion',
      'estado_acta_desocupacion',
    ] as $pdfField) {
      $value = trim((string) ($ticketPayload[$pdfField] ?? ''));
      if ($value !== '') {
        $payload[$pdfField] = $value;
      }
    }
    $payload = $schema->filterTableData($contractTable, $payload);
    if (empty($payload)) {
      return;
    }

    $contractPk = trim((string) ($contract['_ID'] ?? ''));
    if ($contractPk === '') {
      return;
    }
    $db->update($contractTable, $payload, ['_ID' => $contractPk]);
  }

  /**
   * @param array<string,mixed> $ticketPayload
   * @param array<int,string> $notifyRecipients
   * @param array<string,array<string,string>> $generatedPdfs
   */
}
