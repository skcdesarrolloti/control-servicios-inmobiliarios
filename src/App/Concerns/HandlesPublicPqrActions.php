<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;

trait HandlesPublicPqrActions
{
  public function ajax_handler_asignar_pqr_publico(): void
  {
    $this->verifyCsrf();
    $result = [];

    try {
      $result = $this->assign_public_pqr(
        (int) ($_POST['ticket_pk'] ?? 0),
        (string) ($_POST['tema_ayuda'] ?? $_POST['tipo_pqrs'] ?? ''),
        (string) ($_POST['new_empleado_id'] ?? ''),
        '',
        'assigned',
        (string) ($_POST['departamento'] ?? '')
      );
    } catch (\Throwable $e) {
      $this->jsonFail($e->getMessage());
    }

    $this->jsonOk($result);
  }

  /** @return array<string,string> */
  public function assign_public_pqr(int $ticketPk, string $tipoPqrsInput, string $newEmpIdInput = '', string $authorizedEmployeeId = '', string $accessScope = 'assigned', string $departmentInput = ''): array
  {
    $tipoPqrsRaw = trim(sanitize_text_field(wp_unslash($tipoPqrsInput)));
    $newEmpId = trim(sanitize_text_field(wp_unslash($newEmpIdInput)));
    $authorizedEmployeeId = trim(sanitize_text_field(wp_unslash($authorizedEmployeeId)));
    $accessScope = mb_strtolower(trim(sanitize_text_field(wp_unslash($accessScope))), 'UTF-8');
    $departmentInput = trim(sanitize_text_field(wp_unslash($departmentInput)));
    if ($accessScope !== 'all') {
      $accessScope = 'assigned';
    }

    if ($ticketPk <= 0) {
      throw new \InvalidArgumentException('Ticket invalido.');
    }
    if ($tipoPqrsRaw === '') {
      throw new \InvalidArgumentException('Debes seleccionar un tipo de PQR/Peticion.');
    }

    $tipoPqrs = '';
    $tipoNeedle = mb_strtolower($tipoPqrsRaw, 'UTF-8');
    $temaOpts = \SCM\Modules\PublicTickets\PublicTicketsService::getPqrThemes();
    foreach ($temaOpts as $temaOpt) {
      if (mb_strtolower(trim((string) $temaOpt), 'UTF-8') === $tipoNeedle) {
        $tipoPqrs = (string) $temaOpt;
        break;
      }
    }
    if ($tipoPqrs === '') {
      throw new \InvalidArgumentException('Tipo de PQR/Peticion no valido.');
    }

    $ticketsTable = $this->db->table('jet_cct_tickets');
    $ticket = $this->db->getRow(
      "SELECT * FROM `{$ticketsTable}` WHERE `_ID` = ? LIMIT 1",
      [$ticketPk]
    );
    if (!is_array($ticket)) {
      throw new \RuntimeException('Ticket no encontrado.');
    }

    if ($authorizedEmployeeId !== '' && $accessScope !== 'all') {
      $currentEmployeeId = trim((string) ($ticket['id_empleado'] ?? ''));
      if ($currentEmployeeId === '' || $currentEmployeeId !== $authorizedEmployeeId) {
        throw new \RuntimeException('No tienes acceso a esta solicitud.');
      }
    }

    $departmentFromTheme = \SCM\Modules\PublicTickets\PublicTicketsService::getDepartmentForTheme($tipoPqrs);
    $departmentOptions = $this->get_public_pqr_department_options();
    $departmentManual = '';
    if ($departmentInput !== '') {
      foreach ($departmentOptions as $departmentOption) {
        if (mb_strtolower($departmentOption, 'UTF-8') === mb_strtolower($departmentInput, 'UTF-8')) {
          $departmentManual = $departmentOption;
          break;
        }
      }
      if ($departmentManual === '') {
        throw new \InvalidArgumentException('Departamento no valido.');
      }
    }
    $oldTipo = trim((string) ($ticket['tema_ayuda'] ?? $ticket['tipo_pqrs'] ?? ''));
    $oldDepartment = trim((string) ($ticket['departamento'] ?? ''));
    $oldEmpId = trim((string) ($ticket['id_empleado'] ?? ''));
    $oldEmpNombre = trim((string) ($ticket['empleado'] ?? $ticket['nombre_empleado'] ?? ''));
    $tipoCambio = mb_strtolower($oldTipo, 'UTF-8') !== mb_strtolower($tipoPqrs, 'UTF-8');
    $department = $departmentManual;
    if ($department === '') {
      $department = $oldDepartment;
      if ($tipoCambio || $department === '') {
        $department = trim($departmentFromTheme);
        if ($department === '') {
          $department = $oldDepartment !== '' ? $oldDepartment : 'Servicio al cliente';
        }
      }
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $updatePayload = [
      'tema_ayuda' => $tipoPqrs,
      'departamento' => $department,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
    ];

    $newNombre = '';
    if ($newEmpId !== '') {
      $funcTable = $this->db->table('jet_cct_funcionarios');
      $newEmp = $this->db->getRow(
        "SELECT `id_empleado`, `nombre`, `correo`, `celular` FROM `{$funcTable}` WHERE TRIM(COALESCE(`id_empleado`,'')) = ? LIMIT 1",
        [$newEmpId]
      );
      if (!is_array($newEmp)) {
        throw new \RuntimeException('Funcionario no encontrado.');
      }

      $newNombre = trim((string) ($newEmp['nombre'] ?? ''));
      $updatePayload['id_empleado'] = $newEmpId;
      $updatePayload['empleado'] = $newNombre;
      $updatePayload['nombre_empleado'] = $newNombre;
      $updatePayload['correo_empleado'] = trim((string) ($newEmp['correo'] ?? ''));
      $updatePayload['celular_empleado'] = trim((string) ($newEmp['celular'] ?? ''));
      if ($oldEmpId !== $newEmpId) {
        $updatePayload['estado_administrativo'] = 'Trasladado';
      }
    }

    $schema = new \SCM\Support\SchemaInspector($this->db);
    $update = $schema->filterTableData($ticketsTable, $updatePayload);
    if (empty($update)) {
      throw new \RuntimeException('No se pudo actualizar el PQR.');
    }

    $this->db->update($ticketsTable, $update, ['_ID' => $ticketPk]);

    $empCambio = $newEmpId !== '' && $newEmpId !== $oldEmpId;

    if ($tipoCambio || $empCambio) {
      $changes = [];
      if ($tipoCambio) {
        $changes[] = 'Tipo PQR/Peticion: "' . ($oldTipo !== '' ? $oldTipo : '-') . '" -> "' . $tipoPqrs . '"';
      }
      if ($empCambio) {
        $changes[] = 'Funcionario: "' . ($oldEmpNombre !== '' ? $oldEmpNombre : 'sin asignar') . '" -> "' . ($newNombre !== '' ? $newNombre : $newEmpId) . '"';
      }
      $obs = 'Actualizacion Solicitud web. ' . implode(' | ', $changes) . '.';
      $service = $this->get_seguimiento_service();
      $service->addSeguimientoEntry($ticket, $ticketPk, $obs);
    }

    if ($empCambio) {
      $updatedTicket = array_merge($ticket, [
        '_ID' => $ticketPk,
        'id_ticket' => $ticket['id_ticket'] ?? $ticketPk,
        'tema_ayuda' => $tipoPqrs,
        'departamento' => $department,
        'id_empleado' => $newEmpId,
        'empleado' => $newNombre,
        'nombre_empleado' => $newNombre,
        'correo_empleado' => trim((string) ($newEmp['correo'] ?? '')),
        'celular_empleado' => trim((string) ($newEmp['celular'] ?? '')),
      ]);
      $this->notifyPublicPqrTransferToEmployee(
        $updatedTicket,
        $newEmpId,
        $newNombre,
        trim((string) ($newEmp['correo'] ?? '')),
        trim((string) ($newEmp['celular'] ?? '')),
        $oldEmpNombre,
        $tipoPqrs
      );
    }

    $msg = 'PQR actualizado correctamente.';
    if ($newEmpId !== '') {
      $msg .= ' Responsable: ' . ($newNombre !== '' ? $newNombre : $newEmpId) . '.';
    }

    return [
      'message' => $msg,
      'ticket_pk' => (string) $ticketPk,
      'tipo_pqrs' => $tipoPqrs,
      'tema_ayuda' => $tipoPqrs,
      'departamento' => $department,
      'id_empleado' => $newEmpId,
      'empleado' => $newNombre,
    ];
  }

  /** @param array<string,mixed> $ticket */
  private function notifyPublicPqrTransferToEmployee(array $ticket, string $employeeId, string $employeeName, string $employeeEmail, string $employeePhone, string $oldEmployeeName, string $tipoPqrs): void
  {
    if ($employeeId === '' || ($employeeEmail === '' && $employeePhone === '')) {
      return;
    }

    $configFile = defined('SCM_ROOT') ? (SCM_ROOT . '/config/app.php') : '';
    $appConfig = [];
    if ($configFile !== '' && is_readable($configFile)) {
      $loadedConfig = require $configFile;
      if (is_array($loadedConfig)) {
        $appConfig = $loadedConfig;
      }
    }

    $publicService = new \SCM\Modules\PublicTickets\PublicTicketsService($this->db, $appConfig);
    $publicRequestsUrl = $publicService->buildResponsiblePublicPqrUrl($employeeId, 2592000, 'assigned');
    $logicalTicket = trim((string) ($ticket['id_ticket'] ?? $ticket['_ID'] ?? ''));
    $ticketUrl = self::DEFAULT_TICKET_URL . rawurlencode($logicalTicket);
    $contractLabel = trim((string) ($ticket['contrato'] ?? $ticket['id_contrato'] ?? ''));
    $subject = 'Solicitud web trasladada #' . $logicalTicket;
    $displayName = $employeeName !== '' ? $employeeName : ('Funcionario #' . $employeeId);

    if ($employeeEmail !== '' && filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
      $content = '<p style="font-weight:600;margin:0 0 12px;">Se te traslado una solicitud web para gestion.</p>';
      $content .= '<p style="margin:4px 0;"><b>Solicitud:</b> #' . \SCM\Support\EmailTemplate::e($logicalTicket) . '</p>';
      if ($contractLabel !== '') {
        $content .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . \SCM\Support\EmailTemplate::e($contractLabel) . '</p>';
      }
      $content .= '<p style="margin:4px 0;"><b>Tema:</b> ' . \SCM\Support\EmailTemplate::e($tipoPqrs) . '</p>';
      $content .= '<p style="margin:4px 0;"><b>Funcionario anterior:</b> ' . \SCM\Support\EmailTemplate::e($oldEmployeeName !== '' ? $oldEmployeeName : 'Sin asignar') . '</p>';
      $buttons = [];
      if ($publicRequestsUrl !== '') {
        $buttons[] = ['url' => $publicRequestsUrl, 'label' => 'Ver tus solicitudes web'];
      }
      $html = \SCM\Support\EmailTemplate::render($subject, $content, [
        'ticket_url' => $ticketUrl,
        'buttons' => $buttons,
      ]);
      (new \SCM\Support\EmailQueue($this->db))->enqueue($employeeEmail, $subject, $html, [
        'source' => 'public_pqr_transfer',
        'destination_name' => $displayName,
      ]);
    }

    if ($employeePhone !== '') {
      $message = "🔁 *Solicitud web trasladada*\n";
      $message .= '*Solicitud:* #' . $logicalTicket . "\n";
      if ($contractLabel !== '') {
        $message .= '*Contrato:* ' . $contractLabel . "\n";
      }
      $message .= '*Tema:* ' . $tipoPqrs . "\n";
      if ($oldEmployeeName !== '') {
        $message .= '*Funcionario anterior:* ' . $oldEmployeeName . "\n";
      }
      $message .= "\n*Ver solicitud:*\n" . $ticketUrl;
      if ($publicRequestsUrl !== '') {
        $message .= "\n\n*Tus solicitudes web:*\n" . $publicRequestsUrl;
      }
      (new \SCM\Support\SmsQueue($this->db))->enqueue($employeePhone, $displayName, $message, [
        'id_funcionario' => is_numeric($employeeId) ? (int) $employeeId : 0,
        'campaign_tag' => 'pqr_portal_publico_traslado',
        'categoria_mensaje' => 'informacion',
        'dedupe_key' => 'pqr_trasladada_funcionario:' . $logicalTicket . ':' . $employeeId,
        'template_name' => 'pqr_trasladada_funcionario_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $displayName],
              ['type' => 'text', 'text' => $logicalTicket],
              ['type' => 'text', 'text' => $tipoPqrs],
              ['type' => 'text', 'text' => $oldEmployeeName !== '' ? $oldEmployeeName : 'Sin asignar'],
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
    }
  }

  public function ajax_handler_guardar_notif_responsable_pqr(): void
  {
    $this->verifyCsrf();

    $idsRaw = $_POST['notif_responsable_ids'] ?? ($_POST['notif_responsable_id'] ?? []);
    if (!is_array($idsRaw)) {
      $idsRaw = [$idsRaw];
    }

    $allowedCandidates = $this->get_public_pqr_corresponsable_candidates();
    $allowedById = [];
    foreach ($allowedCandidates as $candidate) {
      $cid = trim((string) ($candidate['id'] ?? ''));
      if ($cid !== '') {
        $allowedById[$cid] = trim((string) ($candidate['label'] ?? $cid));
      }
    }

    $notifIds = [];
    foreach ($idsRaw as $idRaw) {
      $id = trim(sanitize_text_field(wp_unslash((string) $idRaw)));
      if ($id === '') {
        continue;
      }
      if (!isset($allowedById[$id])) {
        $this->jsonFail('Uno de los funcionarios seleccionados no existe o esta inactivo.');
      }
      if (!in_array($id, $notifIds, true)) {
        $notifIds[] = $id;
      }
    }

    $saved = \SCM\Modules\PublicTickets\PublicTicketsService::saveNotifResponsables($notifIds);
    if (!$saved) {
      $this->jsonFail('No se pudo guardar la configuracion.');
    }

    $labels = [];
    foreach ($notifIds as $id) {
      $labels[] = isset($allowedById[$id]) ? $allowedById[$id] : $id;
    }
    $message = empty($labels)
      ? 'Sin funcionarios de notificacion.'
      : 'Notificacion guardada para: ' . implode(', ', $labels) . '.';

    $this->jsonOk([
      'message' => $message,
      'notif_responsable_ids' => $notifIds,
    ]);
  }

  public function ajax_handler_filtrar_pqr_publico(): void
  {
    $this->verifyCsrf();

    $employeeIdFilter = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) ($_POST['id_empleado'] ?? '')));
    if (!is_string($employeeIdFilter)) {
      $employeeIdFilter = '';
    }
    $filters = $this->get_public_pqr_filters_from_input($_POST);
    $result = $this->fetch_public_pqr_result(24, $employeeIdFilter, $filters);
    $funcionarios = [];
    try {
      $funcionarios = (array) ($this->get_servicios_inmobiliarios_module()->getFilterOptions()['funcionarios'] ?? []);
    } catch (\Throwable $e) {
      $funcionarios = [];
    }

    $html = $this->render_public_pqr_tab(
      $result,
      $funcionarios,
      self::DEFAULT_TICKET_URL,
      $employeeIdFilter,
      false,
      [],
      $filters
    );

    $this->jsonOk([
      'tab_html' => $html,
      'count' => (string) ((int) ($result['pagination']['total'] ?? 0)),
    ]);
  }

  public function ajax_handler_guardar_corresponsable_pqr_publico(): void
  {
    $this->verifyCsrf();

    $tipoRaw = trim(sanitize_text_field(wp_unslash((string) ($_POST['tema_ayuda'] ?? $_POST['tipo_pqrs'] ?? ''))));
    $corresponsableIdsRaw = $_POST['corresponsable_ids'] ?? [];
    $corresponsableActorRaw = $_POST['corresponsable_actor'] ?? [];

    if ($tipoRaw === '') {
      $this->jsonFail('Debes seleccionar el tipo de PQR/Peticion.');
    }

    $tipoPqrs = '';
    $tipoNeedle = mb_strtolower($tipoRaw, 'UTF-8');
    $tipos = \SCM\Modules\PublicTickets\PublicTicketsService::getPqrThemes();
    foreach ($tipos as $tipo) {
      if (mb_strtolower(trim((string) $tipo), 'UTF-8') === $tipoNeedle) {
        $tipoPqrs = (string) $tipo;
        break;
      }
    }
    if ($tipoPqrs === '') {
      $this->jsonFail('Tipo de PQR/Peticion no valido.');
    }

    if (!is_array($corresponsableIdsRaw)) {
      $corresponsableIdsRaw = [$corresponsableIdsRaw];
    }
    if (!is_array($corresponsableActorRaw)) {
      $corresponsableActorRaw = [];
    }

    $allowedCandidates = $this->get_public_pqr_corresponsable_candidates();
    $allowedById = [];
    foreach ($allowedCandidates as $candidate) {
      $cid = trim((string) ($candidate['id'] ?? ''));
      if ($cid === '') {
        continue;
      }
      $allowedById[$cid] = [
        'id' => $cid,
        'label' => trim((string) ($candidate['label'] ?? $cid)),
      ];
    }

    $normalizeIds = function ($rawIds) use ($allowedById): array {
      if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
      }

      $ids = [];
      foreach ($rawIds as $rawId) {
        $id = trim(sanitize_text_field(wp_unslash((string) $rawId)));
        if ($id === '') {
          continue;
        }
        if (!isset($allowedById[$id])) {
          $this->jsonFail('Uno de los corresponsables seleccionados no existe o esta inactivo.');
        }
        if (!in_array($id, $ids, true)) {
          $ids[] = $id;
        }
      }

      return $ids;
    };

    $corresponsableIds = $normalizeIds($corresponsableIdsRaw);
    $actorAssignments = [];
    foreach (['propietario', 'arrendatario', 'copropiedad', 'cliente'] as $actorKey) {
      $ids = $normalizeIds($corresponsableActorRaw[$actorKey] ?? []);
      if ($ids !== []) {
        $actorAssignments[$actorKey] = $ids;
      }
    }

    $map = \SCM\Modules\PublicTickets\PublicTicketsService::getCorresponsableAssignments();
    if (!empty($actorAssignments)) {
      if (!empty($corresponsableIds)) {
        $actorAssignments['default'] = $corresponsableIds;
      }
      $map[$tipoPqrs] = $actorAssignments;
    } elseif (empty($corresponsableIds)) {
      unset($map[$tipoPqrs]);
    } else {
      $map[$tipoPqrs] = $corresponsableIds;
    }

    $saved = \SCM\Modules\PublicTickets\PublicTicketsService::saveCorresponsableAssignments($map);
    if (!$saved) {
      $this->jsonFail('No se pudo guardar la configuracion de corresponsable.');
    }

    $allSavedIds = $corresponsableIds;
    foreach ($actorAssignments as $ids) {
      foreach ($ids as $id) {
        if (!in_array($id, $allSavedIds, true)) {
          $allSavedIds[] = $id;
        }
      }
    }

    $labels = [];
    foreach ($allSavedIds as $id) {
      $labels[] = (string) ($allowedById[$id]['label'] ?? $id);
    }
    $message = empty($allSavedIds)
      ? 'Corresponsables eliminados para "' . $tipoPqrs . '".'
      : 'Corresponsables guardados para "' . $tipoPqrs . '": ' . implode(', ', $labels) . '.';

    $this->jsonOk([
      'message' => $message,
      'tipo_pqrs' => $tipoPqrs,
      'corresponsable_ids' => $allSavedIds,
      'corresponsable_actor' => $actorAssignments,
      'corresponsable_labels' => $labels,
    ]);
  }
}
