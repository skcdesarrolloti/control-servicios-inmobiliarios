<?php

namespace SCM\Modules\Pending;

use SCM\Core\Auth;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

final class PendingService
{
  private PendingRepository $repo;

  public function __construct(PendingRepository $repo)
  {
    $this->repo = $repo;
  }

  private function normalizeYesNo(string $value): string
  {
    $v = strtolower(trim($value));
    if (in_array($v, ['si', 's—', 'yes', '1', 'true', 'activo'], true)) {
      return 'si';
    }
    if (in_array($v, ['no', '0', 'false', 'inactivo'], true)) {
      return 'no';
    }
    return '';
  }

  /** @return array<string,mixed> */
  public function buildPreventivas(array $filters): array
  {
    $funcionarios = $this->repo->getFuncionarios();
    $rows = $this->repo->getContratosEntregados($filters);

    $year = (int) date('Y');
    $fMes = max(0, min(12, (int) ($filters['mes'] ?? 0)));
    $fTuvo = $this->normalizeYesNo((string) ($filters['tuvo'] ?? ''));
    $fEmpleado = trim((string) ($filters['empleado'] ?? ''));

    $items = [];
    foreach ($rows as $row) {
      $contractId = trim((string) ($row['_ID'] ?? ''));
      if ($contractId === '') {
        $contractId = trim((string) ($row['contrato'] ?? ''));
      }

      $ultTs = $this->parseTs($row['ultima_revision_preventiva'] ?? null);
      $fechaEntregaTs = $this->parseTs($row['fecha_entrega'] ?? null);
      $fechaEntregaYear = $fechaEntregaTs > 0 ? (int) date('Y', $fechaEntregaTs) : 0;
      $ultYear = $ultTs > 0 ? (int) date('Y', $ultTs) : 0;

      if ($fechaEntregaYear === $year) {
        continue;
      }
      if ($ultYear === $year) {
        continue;
      }

      $baseTs = $this->firstPositiveTs([
        $row['fecha_entrega'] ?? null,
        $row['inicio_contrato'] ?? null,
        $row['fecha'] ?? null,
      ]);

      if ($ultTs > 0) {
        $dueTs = $this->addMonths($ultTs, 12);
      } else {
        $baseRef = $baseTs > 0 ? $baseTs : time();
        $dueTs = mktime(0, 0, 0, (int) date('n', $baseRef), (int) date('j', $baseRef), $year);
      }

      $dueMonth = $dueTs > 0 ? (int) date('n', $dueTs) : 0;
      if ($fMes > 0 && $fMes !== $dueMonth) {
        continue;
      }

      $tuvo = $this->normalizeYesNo((string) ($row['tuvo_preventiva'] ?? ''));
      if ($fTuvo !== '' && $tuvo !== $fTuvo) {
        continue;
      }

      $idSucursal = trim((string) ($row['id_sucursal'] ?? $row['sucursal'] ?? ''));
      if ($idSucursal === '') {
        $idSucursal = '1';
      }
      $link = 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-ticket-para-revision-preventiva/?id_contrato=' . rawurlencode($contractId)
        . '&id_inmueble=' . rawurlencode(trim((string) ($row['id_inmueble'] ?? '')))
        . '&id_arrendatario=' . rawurlencode(trim((string) ($row['id_arrendatario'] ?? '')))
        . '&id_propietario=' . rawurlencode(trim((string) ($row['id_propietario'] ?? '')))
        . '&id_sucursal=' . rawurlencode($idSucursal);
      if ($fEmpleado !== '') {
        $link .= '&id_empleado=' . rawurlencode($fEmpleado);
      }

      $items[] = [
        'row' => $row,
        'ultima' => $ultTs,
        'due' => $dueTs,
        'link' => $link,
      ];
    }

    usort($items, static function (array $a, array $b): int {
      $ta = (int) ($a['ultima'] ?? 0);
      $tb = (int) ($b['ultima'] ?? 0);
      if ($ta === $tb) {
        return (int) ((int) (($a['row']['_ID'] ?? 0)) <=> (int) (($b['row']['_ID'] ?? 0)));
      }
      return $ta <=> $tb;
    });

    // Enrich items with existing ticket status (most recent preventiva ticket per contract)
    $contractRows = [];
    foreach ($items as $item) {
      $contractRows[] = $item['row'];
    }
    $tickets = !empty($contractRows)
      ? $this->repo->getTicketsRevisionPreventiva($contractRows)
      : [];
    foreach ($items as &$item) {
      $cid = trim((string) ($item['row']['_ID'] ?? ''));
      $item['ticket'] = $tickets[$cid] ?? null;
    }
    unset($item);

    $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $corte = (string) $year . ($fMes > 0 ? (' — ' . ($months[$fMes] ?? (string) $fMes)) : '');

    return [
      'items' => $items,
      'funcionarios' => $funcionarios,
      'year' => $year,
      'corte' => $corte,
    ];
  }

  /** @return array<string,mixed> */
  public function buildServiciosPublicos(array $filters): array
  {
    $funcionarios = $this->repo->getFuncionarios();
    $rows = $this->repo->getContratosEntregados($filters);

    $nowTs = time();
    $year = (int) date('Y', $nowTs);
    $fMes = max(0, min(12, (int) ($filters['mes'] ?? 0)));

    $items = [];
    foreach ($rows as $row) {
      $contractId = trim((string) ($row['_ID'] ?? ''));
      if ($contractId === '') {
        $contractId = trim((string) ($row['contrato'] ?? ''));
      }

      $ultTs = $this->parseTs($row['ultima_revision_servicios'] ?? null);
      $mesRevisionServicios = (int) ($row['mes_revision_servicios'] ?? 0);

      if ($ultTs > 0 && $mesRevisionServicios >= 1 && $mesRevisionServicios <= 12) {
        $dueTs = $this->replaceMonthPreservingDate($ultTs, $mesRevisionServicios, true);
      } elseif ($ultTs > 0) {
        $dueTs = $ultTs;
      } else {
        $baseTs = $this->firstPositiveTs([
          $row['fecha_entrega'] ?? null,
          $row['inicio_contrato'] ?? null,
          $row['fecha'] ?? null,
        ]);
        if ($mesRevisionServicios >= 1 && $mesRevisionServicios <= 12) {
          $referenceTs = $baseTs > 0 ? $baseTs : $nowTs;
          $dueTs = $this->replaceMonthPreservingDate($referenceTs, $mesRevisionServicios, false, $year);
        } else {
          $dueTs = $baseTs > 0 ? $baseTs : $nowTs;
        }
      }

      $dueMonth = ($mesRevisionServicios >= 1 && $mesRevisionServicios <= 12)
        ? $mesRevisionServicios
        : ($dueTs > 0 ? (int) date('n', $dueTs) : 0);

      if ($fMes > 0 && $fMes !== $dueMonth) {
        continue;
      }

      $link = '/mi-cuenta/anadir-revision-de-servicios-publicos/?id_contrato=' . rawurlencode($contractId)
        . '&id_sucursal=' . rawurlencode((string) ($row['sucursal'] ?? ''));

      $items[] = [
        'row' => $row,
        'ultima' => $ultTs,
        'due' => $dueTs,
        'link' => $link,
      ];
    }

    usort($items, static function (array $a, array $b): int {
      $ta = (int) ($a['due'] ?? 0);
      $tb = (int) ($b['due'] ?? 0);
      if ($ta === $tb) {
        return (int) ((int) (($a['row']['_ID'] ?? 0)) <=> (int) (($b['row']['_ID'] ?? 0)));
      }
      return $ta <=> $tb;
    });

    $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $corte = (string) $year . ($fMes > 0 ? (' — ' . ($months[$fMes] ?? (string) $fMes)) : '');

    return [
      'items' => $items,
      'funcionarios' => $funcionarios,
      'corte' => $corte,
    ];
  }

  /** @return array<string,mixed> */
  public function buildReportesAdministrativos(array $filters = []): array
  {
    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $preTable = $db->table('jet_cct_pre_reportes_administrativos');
    $funcionarios = $this->repo->getFuncionarios();
    if (!$schema->tableExists($preTable)) {
      return [
        'filters' => $filters,
        'funcionarios' => $funcionarios,
        'items' => [],
        'count' => 0,
      ];
    }

    $items = [];
    foreach ($this->repo->getReportesAdministrativosPorVerificar($filters) as $row) {
      $row = (array) $row;
      $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
      $ticketUrl = $ticketRef !== ''
        ? 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($ticketRef)
        : '';

      $items[] = [
        'row' => $row,
        'ticket_url' => $ticketUrl,
      ];
    }

    return [
      'filters' => $filters,
      'funcionarios' => $funcionarios,
      'items' => $items,
      'count' => count($items),
    ];
  }

  /** @return array<string,string> */
  public function approveReporteAdministrativo(int $preId): array
  {
    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $preTable = $db->table('jet_cct_pre_reportes_administrativos');
    $finalTable = $db->table('jet_cct_reportes_administrativos');

    if (!$schema->tableExists($preTable) || !$schema->tableExists($finalTable)) {
      return ['ok' => '0', 'message' => 'No se encontraron las tablas de reportes administrativos.'];
    }

    $row = $this->repo->getPreReporteAdministrativoById($preId);
    if (!is_array($row)) {
      return ['ok' => '0', 'message' => 'Reporte administrativo no encontrado.'];
    }

    $estado = strtolower(trim((string) ($row['estado'] ?? '')));
    if ($estado !== 'pendiente') {
      return ['ok' => '0', 'message' => 'Este reporte ya no está pendiente.'];
    }

    $payload = $row;
    unset($payload['_ID']);
    $payload['fue_pagado'] = 'No';
    $payload['exportado'] = 'No';
    $payload['cct_status'] = trim((string) ($payload['cct_status'] ?? '')) ?: 'publish';

    $payload = $schema->filterTableData($finalTable, $payload);
    if (empty($payload)) {
      return ['ok' => '0', 'message' => 'No se pudo preparar el reporte para aprobación.'];
    }

    $pdo = $db->pdo();
    $shouldCloseTicket = strtolower(trim((string) ($row['cerrar_ticket'] ?? ''))) === 'si';

    try {
      if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
      }

      $this->repo->insertReporteAdministrativo($payload);

      $preUpdate = $schema->filterTableData($preTable, [
        'estado' => 'Aprobado',
        'cct_modified' => date('Y-m-d H:i:s'),
      ]);
      if (!empty($preUpdate)) {
        $this->repo->updatePreReporteAdministrativo($preId, $preUpdate);
      }

      if ($shouldCloseTicket) {
        $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
        $ticket = $this->repo->getTicketByReference($ticketRef);
        if (is_array($ticket) && isset($ticket['_ID'])) {
          $ticketsTable = $db->table('jet_cct_tickets');
          $ticketUpdate = $schema->filterTableData($ticketsTable, [
            'estado' => 'Cerrado',
            'estado_administrativo' => 'Finalizado',
            'fecha_actualizacion' => time(),
            'cct_modified' => date('Y-m-d H:i:s'),
          ]);
          if (!empty($ticketUpdate)) {
            $db->update($ticketsTable, $ticketUpdate, ['_ID' => (int) $ticket['_ID']]);
          }
        }
      }

      if ($pdo->inTransaction()) {
        $pdo->commit();
      }
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      return ['ok' => '0', 'message' => 'No se pudo aprobar el reporte: ' . $e->getMessage()];
    }

    return ['ok' => '1', 'message' => 'Reporte administrativo aprobado.'];
  }

  /** @return array<string,string> */
  public function rejectReporteAdministrativo(int $preId): array
  {
    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $preTable = $db->table('jet_cct_pre_reportes_administrativos');

    if (!$schema->tableExists($preTable)) {
      return ['ok' => '0', 'message' => 'No existe la tabla de pre reportes administrativos.'];
    }

    $row = $this->repo->getPreReporteAdministrativoById($preId);
    if (!is_array($row)) {
      return ['ok' => '0', 'message' => 'Reporte administrativo no encontrado.'];
    }

    $update = $schema->filterTableData($preTable, [
      'estado' => 'Desaprobado',
      'cct_modified' => date('Y-m-d H:i:s'),
    ]);
    if (empty($update)) {
      return ['ok' => '0', 'message' => 'No se pudo actualizar el estado del reporte.'];
    }

    $this->repo->updatePreReporteAdministrativo($preId, $update);
    return ['ok' => '1', 'message' => 'Reporte administrativo desaprobado.'];
  }

  /** @return array<string,mixed> */
  public function buildContratosArrendamiento(array $filters, string $bucket): array
  {
    $data = $this->repo->getContratosArrendamiento($filters, $bucket);
    return [
      'filters' => $filters,
      'bucket' => $bucket,
      'items' => (array) ($data['rows'] ?? []),
      'count' => (int) ($data['total'] ?? 0),
      'pagination' => [
        'page' => (int) ($data['page'] ?? 1),
        'per_page' => (int) ($data['per_page'] ?? 30),
        'total' => (int) ($data['total'] ?? 0),
        'total_pages' => (int) ($data['total_pages'] ?? 1),
      ],
    ];
  }

  /**
   * @param array<string,string> $input
   * @param array<int,string> $imagenes
   * @param array<int,array{nombre_archivo:string,archivo:string}> $documentos
   * @param array<int,string> $notifyRecipients
   * @return array<string,mixed>
   */
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
      $descripcion = 'Espero que se encuentre bien. Se llevará acabo un revision preventiva programada según la fecha de inicio del contrato de arrendamiento. En este ticket se documentará todo el proceso realizado.';
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
  private function queueCreationEmails(int $ticketId, array $ticketPayload, array $notifyRecipients, string $mode, array $generatedPdfs = []): int
  {
    $notifyRecipients = array_values(array_unique(array_filter(array_map('strval', $notifyRecipients))));
    if (empty($notifyRecipients)) {
      $notifyRecipients = ['empleado', 'solicitante', 'admin'];
    }
    if (in_array('none', $notifyRecipients, true)) {
      return 0;
    }

    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode((string) $ticketId);
    $subject = $mode === 'preventiva'
      ? 'Aviso de revision preventiva del contrato ' . (string) ($ticketPayload['contrato'] ?? '')
      : 'Ticket administrativo #' . $ticketId . ' creado';
    $content = '<p style="font-weight:600;margin:0 0 12px;">Se ha creado un ticket administrativo.</p>';
    $content .= '<p style="margin:4px 0;"><b>Ticket:</b> #' . EmailTemplate::e((string) $ticketId) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Tema:</b> ' . EmailTemplate::e((string) ($ticketPayload['tema_ayuda'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e((string) ($ticketPayload['asunto'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . EmailTemplate::e((string) ($ticketPayload['contrato'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Inmueble:</b> ' . EmailTemplate::e((string) ($ticketPayload['inmueble'] ?? '')) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Responsable:</b> ' . EmailTemplate::e((string) ($ticketPayload['nombre_empleado'] ?? '')) . '</p>';

    $jobs = [];
    $addJob = static function (array &$jobs, string $email, string $target, array $pdfs): void {
      $email = trim($email);
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
      }
      $key = strtolower($email);
      if (!isset($jobs[$key])) {
        $jobs[$key] = ['email' => $email, 'targets' => [], 'pdfs' => []];
      }
      $jobs[$key]['targets'][] = $target;
      foreach ($pdfs as $pdfKey => $pdf) {
        $url = trim((string) ($pdf['url'] ?? ''));
        if ($url !== '') {
          $jobs[$key]['pdfs'][$pdfKey] = $pdf;
        }
      }
    };

    foreach ($notifyRecipients as $target) {
      if ($target === 'empleado') {
        $addJob($jobs, (string) ($ticketPayload['correo_empleado'] ?? ''), $target, $generatedPdfs);
      } elseif ($target === 'solicitante') {
        $addJob($jobs, (string) ($ticketPayload['correo_solicitante'] ?? ''), $target, $this->pdfsForTarget($generatedPdfs, (string) ($ticketPayload['solicitante_tipo'] ?? ''), (string) ($ticketPayload['arrendatario'] ?? '')));
      } elseif ($target === 'arrendatario') {
        $addJob($jobs, (string) ($ticketPayload['correo_arrendatario'] ?? ''), $target, $this->pdfsForTarget($generatedPdfs, 'arrendatario', 'arrendatario'));
      } elseif ($target === 'propietario') {
        $addJob($jobs, (string) ($ticketPayload['correo_propietario'] ?? ''), $target, $this->pdfsForTarget($generatedPdfs, 'propietario', 'arrendatario'));
      } elseif ($target === 'admin') {
        $addJob($jobs, 'sucasacorreos@gmail.com', $target, $generatedPdfs);
        $addJob($jobs, $mode === 'preventiva' ? 'mantenimientotickets@hotmail.com' : 'gcorrearivera@gmail.com', $target, $generatedPdfs);
      }
    }
    if (empty($jobs)) {
      return 0;
    }

    $queue = new EmailQueue($this->repo->getDb());
    foreach ($jobs as $job) {
      $pdfButtons = [];
      $attachments = [];
      foreach ((array) ($job['pdfs'] ?? []) as $pdf) {
        $url = trim((string) ($pdf['url'] ?? ''));
        if ($url === '') {
          continue;
        }
        $title = (string) ($pdf['title'] ?? 'Documento PDF');
        $pdfButtons[] = ['url' => $url, 'label' => $title];
        $attachments[] = [
          'title' => $title,
          'url' => $url,
          'path' => (string) ($pdf['path'] ?? ''),
        ];
      }
      $extraContent = $content;
      if (!empty($pdfButtons)) {
        $extraContent .= '<p style="margin:12px 0 4px;"><b>Documentos generados:</b></p>';
        foreach ($pdfButtons as $button) {
          $extraContent .= '<p style="margin:4px 0;">' . EmailTemplate::e((string) $button['label']) . '</p>';
        }
      }
      $html = EmailTemplate::render($subject, $extraContent, [
        'ticket_url' => $ticketUrl,
        'buttons' => $pdfButtons,
      ]);
      $queue->enqueue((string) $job['email'], $subject, $html, [
        'source' => 'scm_ticket_administrativo_nativo',
        'dedupe_key' => 'ticket_admin_created_' . $ticketId,
        'attachments' => $attachments,
        'meta' => [
          'attachments' => $attachments,
          'targets' => array_values(array_unique((array) ($job['targets'] ?? []))),
        ],
      ]);
    }
    return count($jobs);
  }

  /**
   * @param array<string,array<string,string>> $pdfs
   * @return array<string,array<string,string>>
   */
  private function pdfsForTarget(array $pdfs, string $target, string $arrendatarioName): array
  {
    if (isset($pdfs['acta_desocupacion'])) {
      return ['acta_desocupacion' => $pdfs['acta_desocupacion']];
    }
    $target = strtolower(trim($target));
    $arrendatarioName = strtolower(trim($arrendatarioName));
    if ($target === 'arrendatario' || ($arrendatarioName !== '' && $target === $arrendatarioName)) {
      return isset($pdfs['acta_revision_preventiva_arrendatario'])
        ? ['acta_revision_preventiva_arrendatario' => $pdfs['acta_revision_preventiva_arrendatario']]
        : [];
    }
    if ($target === 'propietario') {
      return isset($pdfs['acta_revision_preventiva_propietario'])
        ? ['acta_revision_preventiva_propietario' => $pdfs['acta_revision_preventiva_propietario']]
        : [];
    }
    return $pdfs;
  }

  private function parseTs($value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }
    if (is_numeric($value)) {
      $ts = (int) $value;
      if ($ts > 9999999999) {
        $ts = (int) floor($ts / 1000);
      }
      return $ts > 0 ? $ts : 0;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? 0 : (int) $ts;
  }

  private function addMonths(int $ts, int $months): int
  {
    if ($ts <= 0 || $months <= 0) {
      return 0;
    }
    try {
      $dt = new \DateTime('@' . $ts);
      $dt->setTimezone(new \DateTimeZone(date_default_timezone_get() ?: 'UTC'));
      $dt->modify('+' . $months . ' months');
      return (int) $dt->getTimestamp();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  private function replaceMonthPreservingDate(int $ts, int $month, bool $ensureFuture = false, ?int $forcedYear = null): int
  {
    if ($ts <= 0 || $month < 1 || $month > 12) {
      return 0;
    }

    try {
      $tz = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
      $source = new \DateTime('@' . $ts);
      $source->setTimezone($tz);

      $targetYear = $forcedYear ?? (int) $source->format('Y');
      $targetDay = (int) $source->format('j');
      $targetHour = (int) $source->format('G');
      $targetMinute = (int) $source->format('i');
      $targetSecond = (int) $source->format('s');

      $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $targetYear);
      $candidate = clone $source;
      $candidate->setDate($targetYear, $month, min($targetDay, $maxDay));
      $candidate->setTime($targetHour, $targetMinute, $targetSecond);

      if ($ensureFuture && $candidate->getTimestamp() <= $ts) {
        $targetYear++;
        $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $targetYear);
        $candidate->setDate($targetYear, $month, min($targetDay, $maxDay));
        $candidate->setTime($targetHour, $targetMinute, $targetSecond);
      }

      return (int) $candidate->getTimestamp();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  /** @param array<int,mixed> $values */
  private function firstPositiveTs(array $values): int
  {
    foreach ($values as $value) {
      $ts = $this->parseTs($value);
      if ($ts > 0) {
        return $ts;
      }
    }
    return 0;
  }
}
