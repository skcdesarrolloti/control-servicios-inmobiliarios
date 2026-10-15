<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\PreventiveReview\PreventiveReviewChecklist;
use SCM\Support\SchemaInspector;

/**
 * Revisión preventiva nativa. Replica el formulario JetForm "Añadir revisión preventiva":
 * checklist del inmueble y del contrato, daños (igual que la correctiva), cobro administrativo
 * al crear (tarifa de revisión + transporte) y las mismas actualizaciones de caso, contrato e inmueble.
 */
trait HandlesPreventiveReviewActions
{
  public function canAccessPreventiveReview(int $ticketId): bool
  {
    return $this->canAccessCorrectiveReview($ticketId);
  }

  public function ajax_handler_preventive_review(): void
  {
    $this->verifyCsrf();
    $operation = trim((string) ($_POST['operation'] ?? 'read'));
    $isSendOperation = in_array($operation, ['send_form', 'send'], true);
    // Enviar el informe es un permiso aparte: no todos los cargos que hacen la revisión pueden enviarla.
    if (!$this->canUseDashboardAction($isSendOperation ? 'preventive_review_send' : 'preventive_review_manage')) {
      $this->jsonFail($isSendOperation ? 'No tienes permiso para enviar revisiones preventivas.' : 'No tienes permiso para gestionar revisiones preventivas.');
    }
    $ticketId = (int) ($_POST['ticket_pk'] ?? 0);
    try {
      if (!$this->canAccessPreventiveReview($ticketId)) {
        http_response_code(403);
        $this->jsonFail('No tienes permiso para gestionar la revisión preventiva de este caso.');
      }
      $reviewId = (int) ($_POST['review_id'] ?? 0);
      $result = [];
      $editId = 0;
      if ($operation === 'send_form') {
        $context = $this->preventiveReviewContext($ticketId);
        $this->jsonOk(['html' => $this->renderPreventiveReviewSendForm($context, $this->preventiveReviewSendTarget($context, $reviewId))]);
      }
      if ($operation === 'send') {
        $result = $this->preventiveReviewSend($ticketId, $reviewId);
        if (!$this->canUseDashboardAction('preventive_review_manage')) {
          $this->jsonOk($result + ['html' => $this->renderPreventiveReviewSentNotice((int) $result['review_id'])]);
        }
      } elseif ($operation === 'create') {
        $result = $this->preventiveReviewCreate($ticketId);
      } elseif ($operation === 'edit') {
        $this->preventiveReviewTargetRow($this->preventiveReviewContext($ticketId), $reviewId);
        $editId = $reviewId;
      } elseif ($operation === 'update') {
        $result = $this->preventiveReviewUpdate($ticketId, $reviewId);
      } elseif ($operation === 'delete') {
        $result = $this->preventiveReviewDelete($ticketId, $reviewId);
      } elseif ($operation !== 'read') {
        throw new \DomainException('Operación de revisión preventiva no válida.');
      }
      $this->jsonOk($result + [
        'html' => $this->renderPreventiveReviewPanel($this->preventiveReviewContext($ticketId), $editId),
      ]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }

  /** @return array<string,mixed> */
  private function preventiveReviewContext(int $ticketId): array
  {
    $ticket = $this->correctiveReviewTicket($ticketId);
    if (!$ticket) {
      throw new \DomainException('No se encontró el caso seleccionado.');
    }
    $contract = $this->correctiveReviewContract($ticket);
    $property = $this->correctiveReviewProperty($ticket, $contract);
    return [
      'ticket' => $ticket,
      'contract' => $contract,
      'property' => $property,
      'reviews' => $this->preventiveReviewRows($ticketId, $ticket),
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function preventiveReviewRows(int $ticketId, array $ticket): array
  {
    $table = $this->db->table('jet_cct_revision_preventiva');
    if (!$this->table_exists($table)) {
      return [];
    }
    $byId = [];
    $ids = $this->correctiveReviewSplitIds((string) ($ticket['id_revision_preventiva'] ?? ''));
    if ($ids) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      foreach ($this->db->getResults("SELECT * FROM `{$table}` WHERE `_ID` IN ({$placeholders})", $ids) as $row) {
        $byId[(string) ($row['_ID'] ?? '')] = $row;
      }
    }
    foreach ($this->db->getResults("SELECT * FROM `{$table}` WHERE `id_ticket` = ? ORDER BY `_ID` DESC LIMIT 10", [$ticketId]) as $row) {
      $byId[(string) ($row['_ID'] ?? '')] = $row;
    }
    krsort($byId, SORT_NUMERIC);
    return array_values($byId);
  }

  /** @param array<string,mixed> $context @return array<string,mixed> */
  private function preventiveReviewTargetRow(array $context, int $reviewId): array
  {
    if ($reviewId <= 0) {
      throw new \DomainException('Selecciona una revisión preventiva válida.');
    }
    foreach ((array) ($context['reviews'] ?? []) as $review) {
      if ((int) ($review['_ID'] ?? 0) === $reviewId) {
        return $review;
      }
    }
    throw new \DomainException('No se encontró la revisión preventiva de este caso.');
  }

  /**
   * Lee la checklist enviada: un arreglo por repeater con sus preguntas y observaciones.
   *
   * @return array<string,string> columna => valor serializado
   */
  private function preventiveReviewInputChecklist(): array
  {
    $out = $this->preventiveReviewParseChecklist(PreventiveReviewChecklist::blocks());
    if (trim((string) (PreventiveReviewChecklist::storedRow($out['cumplimiento'] ?? '')['observaciones_cumplimiento'] ?? '')) === '') {
      throw new \DomainException('Describe el uso principal que actualmente se desarrolla en el inmueble.');
    }
    return $out;
  }

  /**
   * @param array<string,array<string,mixed>> $blocks
   * @return array<string,string> columna => valor serializado
   */
  private function preventiveReviewParseChecklist(array $blocks): array
  {
    $raw = is_array($_POST['checklist'] ?? null) ? $_POST['checklist'] : [];
    $allowed = array_keys(PreventiveReviewChecklist::answerOptions());
    $out = [];
    foreach ($blocks as $key => $block) {
      $input = is_array($raw[$key] ?? null) ? $raw[$key] : [];
      $row = [];
      foreach ($block['questions'] as $index => $question) {
        $field = PreventiveReviewChecklist::questionField($key, $index);
        $value = $this->correctiveReviewText($input[$field] ?? '');
        if (($question['type'] ?? '') === 'text') {
          $value = mb_substr($value, 0, 500);
        } elseif ($value !== '' && !in_array($value, $allowed, true)) {
          throw new \DomainException('Respuesta no válida en ' . $block['label'] . '.');
        }
        $row[$field] = $value;
      }
      $observationField = PreventiveReviewChecklist::observationField($key);
      $row[$observationField] = mb_substr($this->preventiveReviewMultiline($input[$observationField] ?? ''), 0, 3000);
      $out[$key] = serialize([0 => $row]);
    }
    return $out;
  }

  /** @return array{found:string,items:array<int,array<string,string>>} */
  private function preventiveReviewInputDamages(): array
  {
    $found = $this->correctiveReviewText($_POST['encontro_danos'] ?? '');
    if (!in_array($found, ['Si', 'No'], true)) {
      throw new \DomainException('Indica si encontraste daños en el inmueble.');
    }
    if ($found === 'No') {
      return ['found' => 'No', 'items' => []];
    }
    $items = $this->correctiveReviewInputItems();
    if (!$items) {
      throw new \DomainException('Registra al menos un daño o marca que no se encontraron daños.');
    }
    return ['found' => 'Si', 'items' => $items];
  }

  private function preventiveReviewMultiline($value): string
  {
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace("/[ \t]+/", ' ', $text) ?: '';
    $text = preg_replace("/\R{3,}/", "\n\n", $text) ?: '';
    return trim($text);
  }

  /** @return array{fee:?int,transport_base:?int,options:array<int,int>} */
  private function preventiveReviewChargeConfig(): array
  {
    $config = [];
    $configTable = $this->db->table('jet_cct_confi_sistema');
    if ($this->table_exists($configTable)) {
      foreach ($this->db->getResults("SELECT `funcion`, `valor` FROM `{$configTable}` WHERE `funcion` IN ('salario','dias_trabajo','porcentaje_smlmv_co_pre','porcentaje_smlmv','valor_transporte') ORDER BY `_ID`") as $row) {
        $config[(string) ($row['funcion'] ?? '')] = $row['valor'] ?? '';
      }
    }
    $fee = \SCM\Modules\TicketCompletion\CompletionPolicy::fee($config);
    $base = \SCM\Modules\TicketCompletion\CompletionPolicy::transportBase($config);
    $options = [0];
    if ($base !== null) {
      $options[] = $base;
      $options[] = $base * 2;
    } else {
      $options[] = 8000;
    }
    return ['fee' => $fee, 'transport_base' => $base, 'options' => array_values(array_unique($options))];
  }

  private function preventiveReviewInputTransport(array $charge): int
  {
    $value = (int) preg_replace('/\D+/', '', (string) ($_POST['transporte'] ?? '0'));
    if (!in_array($value, $charge['options'], true)) {
      throw new \DomainException('Selecciona un valor de transporte válido.');
    }
    return $value;
  }

  /** @return array<string,mixed> */
  private function preventiveReviewCreate(int $ticketId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_preventiva');
    $ticketTable = $this->db->table('jet_cct_tickets');
    foreach ([$reviewTable, $ticketTable] as $table) {
      if (!$schema->tableExists($table)) {
        throw new \DomainException('No está disponible la tabla requerida para guardar la revisión preventiva.');
      }
    }
    $context = $this->preventiveReviewContext($ticketId);
    if (!empty($context['reviews'])) {
      throw new \DomainException('Este caso ya tiene revisión preventiva. Usa Editar para modificarla.');
    }
    $ticket = $context['ticket'];
    $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
    $property = is_array($context['property'] ?? null) ? $context['property'] : [];
    $this->correctiveReviewValidateContext($ticket, $contract, $property);

    $checklist = $this->preventiveReviewInputChecklist();
    $damages = $this->preventiveReviewInputDamages();
    $charge = $this->preventiveReviewChargeConfig();
    if ($charge['fee'] === null || $charge['fee'] <= 0) {
      throw new \DomainException('No está configurada la tarifa de revisión (salario, días de trabajo y porcentaje). Revisa la configuración del sistema antes de crear la revisión.');
    }
    $transport = $this->preventiveReviewInputTransport($charge);

    $storedPhotos = [];
    try {
      $items = $damages['items'] ? $this->correctiveReviewAttachUploadedPhotos($damages['items'], $storedPhotos) : [];
      $actor = $this->ticketCompletionActor();
      $actorEmployeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
      $now = time();
      $nowSql = date('Y-m-d H:i:s', $now);
      $branch = $this->preventiveReviewBranch($ticket, $contract);
      $data = [
        'cct_status' => 'publish',
        'fecha' => $now,
        'evaluacion_de_danos' => serialize($items),
        'encontro_danos' => $damages['found'],
        'recomendaciones' => mb_substr($this->preventiveReviewMultiline($_POST['recomendaciones'] ?? ''), 0, 3000),
        'tip_inm' => $this->correctiveReviewFirstText([$ticket['tipo_inmueble'] ?? '', $contract['tipo_inmueble'] ?? '', $property['tipo_inmueble'] ?? '']),
        'tiene_cotizacion' => 'No',
        'se_envio' => 'No',
        'id_empleado' => $actorEmployeeId,
        'cct_author_id' => $actorEmployeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'creador' => $actor['name'] ?? Auth::user(),
        'email_creador' => $actor['email'] ?? '',
        'celular_creador' => $actor['phone'] ?? '',
        'coordinador' => $branch['nombre_contractual'] ?? '',
        'email_coordinador' => $branch['correo_contractual'] ?? '',
        'celular_coordinador' => $branch['celular_contractual'] ?? '',
        'id_ticket' => $ticketId,
        'id_inmueble' => $this->correctiveReviewFirstText([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '']),
        'id_contrato' => $this->correctiveReviewFirstText([$ticket['id_contrato'] ?? '', $contract['_ID'] ?? '']),
        'contrato' => ltrim($this->correctiveReviewFirstText([$contract['contrato'] ?? '', $ticket['contrato'] ?? '']), '#'),
        'inmueble' => $this->correctiveReviewFirstText([$contract['inmueble'] ?? '', $ticket['inmueble'] ?? '']),
        'sucursal' => $this->correctiveReviewFirstText([$ticket['id_sucursal'] ?? '', $contract['sucursal'] ?? '', $property['sucursal'] ?? '']),
        'id_propietario' => $this->correctiveReviewFirstText([$ticket['id_propietario'] ?? '', $contract['id_propietario'] ?? '']),
        'id_arrendatario' => $this->correctiveReviewFirstText([$ticket['id_arrendatario'] ?? '', $contract['id_arrendatario'] ?? '']),
        'propietario' => $this->correctiveReviewFirstText([$contract['propietario'] ?? '', $ticket['propietario'] ?? '']),
        'arrendatario' => $this->correctiveReviewFirstText([$contract['arrendatario'] ?? '', $ticket['arrendatario'] ?? '']),
        'email_propietario' => $this->correctiveReviewFirstText([$contract['correo_propietario'] ?? '', $ticket['correo_propietario'] ?? '']),
        'celular_propietario' => $this->correctiveReviewFirstText([$contract['celular_propietario'] ?? '', $ticket['celular_propietario'] ?? '']),
        'email_arrendatario' => $this->correctiveReviewFirstText([$contract['correo_arrendatario'] ?? '', $ticket['correo_arrendatario'] ?? '']),
        'celular_arrendatario' => $this->correctiveReviewFirstText([$contract['celular_arrendatario'] ?? '', $ticket['celular_arrendatario'] ?? '']),
        'direccion' => $this->correctiveReviewFirstText([$contract['direccion'] ?? '', $ticket['direccion'] ?? '', $property['direccion'] ?? '']),
        'tipo_negocio' => $this->correctiveReviewFirstText([$ticket['tipo_negocio'] ?? '', $property['tipo_negocio'] ?? '', $contract['gestion_inmueble'] ?? '']),
        'destinacion' => $this->correctiveReviewFirstText([$ticket['destinacion'] ?? '', $property['destinacion'] ?? '', $contract['destinacion_inmueble'] ?? '']),
      ] + $checklist;
      $insert = $schema->filterTableData($reviewTable, $data);
      if (!$this->db->insert($reviewTable, $insert)) {
        throw new \DomainException('No fue posible guardar la revisión preventiva.');
      }
      $reviewId = (int) $this->db->lastInsertId();
      if ($reviewId <= 0) {
        throw new \DomainException('La revisión preventiva se guardó sin identificador válido.');
      }

      $this->preventiveReviewHistory($schema, $ticketId, $reviewId, $actor, $actorEmployeeId, 'Se ha elaborado el informe de acuerdo a la revisión preventiva realizada. Si se encontraron daños realizaremos la cotización para dar solución.', $insert['id_inmueble'] ?? '', 'Se ha realizado una revisión preventiva a su inmueble.');

      $ticketUpdate = $schema->filterTableData($ticketTable, [
        'estado' => 'En proceso',
        'estado_administrativo' => 'Inspeccionado',
        'estado_rev_preventiva' => 'Si',
        'estado_acta_cotizacion_mantenimiento' => 'No',
        'estado_acta_satisfaccion' => 'No',
        'estado_cotizacion_mantenimiento' => 'No',
        'tuvo_reporte' => 'Si',
        'reportes' => (string) (((int) ($ticket['reportes'] ?? 0)) + 1),
        'se_encontraron_danos' => $damages['found'],
        'fecha_actualizacion' => $now,
        'id_revision_preventiva' => (string) $reviewId,
      ]);
      if ($ticketUpdate) {
        $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
      }

      $count = $this->preventiveReviewUpdateContract($schema, $contract, (string) $reviewId, 1, $now);
      $this->preventiveReviewUpdatePropertyCounter((string) ($insert['id_inmueble'] ?? ''), $count);

      $reportId = $this->preventiveReviewEnsureReport($schema, $reviewId, $insert, $ticket, $actor, $actorEmployeeId, (int) $charge['fee'], $transport, $now, $nowSql);
      // La revisión ya quedó guardada: un fallo de la cola no debe reportarla como no creada.
      try {
        $queued = $this->preventiveReviewEnqueueCreatedNotifications($insert, $ticket, $actor, $reviewId);
      } catch (\Throwable $notifyError) {
        $queued = 0;
        error_log('[revision-preventiva] notificaciones #' . $reviewId . ': ' . $notifyError->getMessage());
      }

      return [
        'message' => 'Revisión preventiva #' . $reviewId . ' guardada.'
          . ($reportId > 0 ? ' Cobro administrativo registrado por $' . number_format((int) $charge['fee'] + $transport, 0, ',', '.') . '.' : ' No se pudo registrar el cobro administrativo; revísalo en Reportes administrativos.')
          . ($queued > 0 ? ' Correos en cola: ' . $queued . '.' : ''),
        'review_id' => (string) $reviewId,
        'review_url' => self::preventiveReviewPublicUrl($reviewId),
      ];
    } catch (\Throwable $error) {
      if ($storedPhotos) {
        $this->storedFiles()->deleteStoredImages($storedPhotos);
      }
      if ($error instanceof \DomainException) {
        throw $error;
      }
      error_log('[revision-preventiva] create ticket #' . $ticketId . ': ' . $error->getMessage());
      throw new \DomainException('No fue posible guardar la revisión preventiva.');
    }
  }

  /** @return array<string,mixed> */
  private function preventiveReviewUpdate(int $ticketId, int $reviewId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_preventiva');
    $ticketTable = $this->db->table('jet_cct_tickets');
    $context = $this->preventiveReviewContext($ticketId);
    $review = $this->preventiveReviewTargetRow($context, $reviewId);
    $checklist = $this->preventiveReviewInputChecklist();
    $damages = $this->preventiveReviewInputDamages();
    $charge = $this->preventiveReviewChargeConfig();
    $report = $this->preventiveReviewReport($reviewId);
    $reportLocked = $this->preventiveReviewReportLocked($report);
    $transport = $reportLocked ? (int) ($report['transporte'] ?? 0) : $this->preventiveReviewInputTransport($charge);

    $storedPhotos = [];
    try {
      $items = $damages['items'] ? $this->correctiveReviewAttachUploadedPhotos($damages['items'], $storedPhotos) : [];
      $actor = $this->ticketCompletionActor();
      $actorEmployeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
      $now = time();
      $nowSql = date('Y-m-d H:i:s', $now);
      $update = $schema->filterTableData($reviewTable, [
        'evaluacion_de_danos' => serialize($items),
        'encontro_danos' => $damages['found'],
        'recomendaciones' => mb_substr($this->preventiveReviewMultiline($_POST['recomendaciones'] ?? ''), 0, 3000),
        'cct_modified' => $nowSql,
      ] + $checklist);
      if (!$update || $this->db->update($reviewTable, $update, ['_ID' => $reviewId]) < 0) {
        throw new \DomainException('No fue posible actualizar la revisión preventiva.');
      }
      $ticketUpdate = $schema->filterTableData($ticketTable, ['se_encontraron_danos' => $damages['found'], 'fecha_actualizacion' => $now]);
      if ($ticketUpdate) {
        $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
      }
      $this->preventiveReviewHistory($schema, $ticketId, $reviewId, $actor, $actorEmployeeId, 'Se actualizó la revisión preventiva #' . $reviewId . '.', (string) ($review['id_inmueble'] ?? ''), 'Se actualizó la revisión preventiva #' . $reviewId . ' del inmueble.');

      $chargeMessage = '';
      if ($report && !$reportLocked && $transport !== (int) ($report['transporte'] ?? 0)) {
        $fee = (int) ($report['valor_revision'] ?? $report['valor_mantenimiento'] ?? 0);
        $reportUpdate = $schema->filterTableData($this->db->table('jet_cct_reportes_administrativos'), [
          'transporte' => (string) $transport,
          'valor' => (string) ($fee + $transport),
          'cct_modified' => $nowSql,
        ]);
        if ($reportUpdate) {
          $this->db->update($this->db->table('jet_cct_reportes_administrativos'), $reportUpdate, ['_ID' => (int) $report['_ID']]);
          $chargeMessage = ' Cobro ajustado a $' . number_format($fee + $transport, 0, ',', '.') . '.';
        }
      } elseif (!$report && $charge['fee']) {
        $merged = array_merge($review, $update);
        if ($this->preventiveReviewEnsureReport($schema, $reviewId, $merged, $context['ticket'], $actor, $actorEmployeeId, (int) $charge['fee'], $transport, $now, $nowSql) > 0) {
          $chargeMessage = ' Se registró el cobro administrativo pendiente.';
        }
      }

      return [
        'message' => 'Revisión preventiva #' . $reviewId . ' actualizada.' . $chargeMessage,
        'review_id' => (string) $reviewId,
        'review_url' => self::preventiveReviewPublicUrl($reviewId),
      ];
    } catch (\Throwable $error) {
      if ($storedPhotos) {
        $this->storedFiles()->deleteStoredImages($storedPhotos);
      }
      if ($error instanceof \DomainException) {
        throw $error;
      }
      error_log('[revision-preventiva] update #' . $reviewId . ': ' . $error->getMessage());
      throw new \DomainException('No fue posible actualizar la revisión preventiva.');
    }
  }

  /** @return array<string,mixed> */
  private function preventiveReviewDelete(int $ticketId, int $reviewId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_preventiva');
    $ticketTable = $this->db->table('jet_cct_tickets');
    $context = $this->preventiveReviewContext($ticketId);
    $review = $this->preventiveReviewTargetRow($context, $reviewId);
    $report = $this->preventiveReviewReport($reviewId);
    if ($this->preventiveReviewReportLocked($report)) {
      throw new \DomainException('No se puede eliminar: el cobro administrativo de esta revisión ya fue exportado o pagado.');
    }
    if ($this->db->delete($reviewTable, ['_ID' => $reviewId]) !== 1) {
      throw new \DomainException('No fue posible eliminar la revisión preventiva.');
    }
    if ($report) {
      $this->db->delete($this->db->table('jet_cct_reportes_administrativos'), ['_ID' => (int) $report['_ID']]);
    }
    $ticket = $context['ticket'];
    $remaining = array_values(array_diff($this->correctiveReviewSplitIds((string) ($ticket['id_revision_preventiva'] ?? '')), [(string) $reviewId]));
    $ticketUpdate = $schema->filterTableData($ticketTable, [
      'id_revision_preventiva' => implode(',', $remaining),
      'estado_rev_preventiva' => $remaining ? 'Si' : 'No',
      'reportes' => (string) max(0, ((int) ($ticket['reportes'] ?? 0)) - 1),
      'fecha_actualizacion' => time(),
    ]);
    if ($ticketUpdate) {
      $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
    }
    $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
    $replacement = (string) ($contract['id_revision_preventiva'] ?? '') === (string) $reviewId ? (string) (end($remaining) ?: '') : null;
    $count = $this->preventiveReviewUpdateContract($schema, $contract, $replacement, -1, null);
    $this->preventiveReviewUpdatePropertyCounter((string) ($review['id_inmueble'] ?? ''), $count);

    $actor = $this->ticketCompletionActor();
    $actorEmployeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
    $this->preventiveReviewHistory($schema, $ticketId, 0, $actor, $actorEmployeeId, 'Se eliminó la revisión preventiva #' . $reviewId . ($report ? ' y su cobro administrativo pendiente.' : '.'), '', '');

    return [
      'message' => 'Revisión preventiva #' . $reviewId . ' eliminada' . ($report ? ' junto con su cobro administrativo pendiente.' : '.'),
      'review_id' => (string) $reviewId,
    ];
  }

  /** @param array<string,mixed> $actor */
  private function preventiveReviewHistory(SchemaInspector $schema, int $ticketId, int $reviewId, array $actor, string $employeeId, string $ticketMessage, string $propertyId, string $propertyMessage): void
  {
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($historyTable)) {
      $history = $schema->filterTableData($historyTable, [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_ticket' => $ticketId,
        'fecha' => $now,
        'nombre' => $actor['name'] ?? Auth::user(),
        'correo' => $actor['email'] ?? '',
        'celular' => $actor['phone'] ?? '',
        'respuesta' => '<p>' . htmlspecialchars($ticketMessage, ENT_QUOTES, 'UTF-8') . '</p>',
        'id_revision_preventiva' => $reviewId > 0 ? (string) $reviewId : '',
        'id_empleado' => $employeeId,
      ]);
      if ($history) {
        $this->db->insert($historyTable, $history);
      }
    }
    $propertyHistoryTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($propertyMessage !== '' && $propertyId !== '' && $schema->tableExists($propertyHistoryTable)) {
      $history = $schema->filterTableData($propertyHistoryTable, [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'id_empleado' => $employeeId,
        'id_inmueble' => $propertyId,
        'fecha' => $now,
        'tipo_reporte' => 'Revision preventiva',
        'observacion' => $propertyMessage,
        'funcionario' => $actor['name'] ?? Auth::user(),
        'id_ticket' => (string) $ticketId,
      ]);
      if ($history) {
        $this->db->insert($propertyHistoryTable, $history);
      }
    }
  }

  /**
   * Actualiza el contrato como lo hacía JetForm: última revisión, fecha y contador.
   * Con $reviewId null no cambia el id vinculado. Devuelve el contador resultante (-1 si no aplica).
   *
   * @param array<string,mixed> $contract
   */
  private function preventiveReviewUpdateContract(SchemaInspector $schema, array $contract, ?string $reviewId, int $delta, ?int $date): int
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractId = (int) ($contract['_ID'] ?? 0);
    if ($contractId <= 0 || !$schema->tableExists($table)) {
      return -1;
    }
    $count = max(0, ((int) ($contract['revisiones_preventivas'] ?? 0)) + $delta);
    $data = ['revisiones_preventivas' => (string) $count];
    if ($reviewId !== null) {
      $data['id_revision_preventiva'] = $reviewId;
    }
    if ($date !== null) {
      $data['ultima_revision_preventiva'] = $date;
    }
    $data = $schema->filterTableData($table, $data);
    if ($data) {
      $this->db->update($table, $data, ['_ID' => $contractId]);
    }
    return $count;
  }

  /** Contador "revisiones-preventivas" del post del inmueble en WordPress (antes lo hacía JetForm). */
  private function preventiveReviewUpdatePropertyCounter(string $postId, int $count): void
  {
    $postId = preg_replace('/\D+/', '', $postId) ?: '';
    $table = $this->db->table('postmeta');
    if ($postId === '' || $count < 0 || !$this->table_exists($table)) {
      return;
    }
    try {
      $metaId = (int) $this->db->getVar("SELECT `meta_id` FROM `{$table}` WHERE `post_id` = ? AND `meta_key` = 'revisiones-preventivas' LIMIT 1", [(int) $postId]);
      if ($metaId > 0) {
        $this->db->update($table, ['meta_value' => (string) $count], ['meta_id' => $metaId]);
      } else {
        $this->db->insert($table, ['post_id' => (int) $postId, 'meta_key' => 'revisiones-preventivas', 'meta_value' => (string) $count]);
      }
    } catch (\Throwable $error) {
      error_log('[revision-preventiva] contador inmueble #' . $postId . ': ' . $error->getMessage());
    }
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $contract @return array<string,string> */
  private function preventiveReviewBranch(array $ticket, array $contract): array
  {
    $table = $this->db->table('jet_cct_sucursales');
    $id = $this->correctiveReviewDigits($this->correctiveReviewFirstText([$ticket['id_sucursal'] ?? '', $contract['sucursal'] ?? '']));
    if ($id === '' || !$this->table_exists($table)) {
      return [];
    }
    $row = $this->db->getRow("SELECT `nombre_contractual`, `celular_contractual`, `correo_contractual` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $id]);
    return is_array($row) ? array_map(static fn($value): string => trim((string) $value), $row) : [];
  }

  /** @return array<string,mixed> */
  private function preventiveReviewReport(int $reviewId): array
  {
    $table = $this->db->table('jet_cct_reportes_administrativos');
    if ($reviewId <= 0 || !$this->table_exists($table)) {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_revision_preventiva`, '')) = ? ORDER BY `_ID` DESC LIMIT 1", [(string) $reviewId]) ?: [];
  }

  /** @param array<string,mixed> $report */
  private function preventiveReviewReportLocked(array $report): bool
  {
    if (!$report) {
      return false;
    }
    foreach (['exportado', 'fue_pagado'] as $field) {
      if (strcasecmp(trim((string) ($report[$field] ?? '')), 'Si') === 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * Cobro administrativo de la revisión (antes lo creaba el hook "reporte-administrativos-unificado").
   *
   * @param array<string,mixed> $review @param array<string,mixed> $ticket @param array<string,mixed> $actor
   */
  private function preventiveReviewEnsureReport(SchemaInspector $schema, int $reviewId, array $review, array $ticket, array $actor, string $employeeId, int $fee, int $transport, int $now, string $nowSql): int
  {
    $table = $this->db->table('jet_cct_reportes_administrativos');
    if (!$schema->tableExists($table) || $reviewId <= 0 || $fee <= 0) {
      return 0;
    }
    $existing = $this->preventiveReviewReport($reviewId);
    if ($existing) {
      return (int) $existing['_ID'];
    }
    $propertyCode = trim((string) ($review['inmueble'] ?? ''));
    $payload = $schema->filterTableData($table, [
      'cct_status' => 'publish',
      'id_ticket' => (string) ($ticket['_ID'] ?? $review['id_ticket'] ?? ''),
      'id_empleado' => $employeeId,
      'creador' => $actor['name'] ?? Auth::user(),
      'cct_author_id' => $employeeId,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'fecha' => $now,
      'fue_pagado' => 'No',
      'exportado' => 'No',
      'categoria' => 'Revision preventiva',
      'descripcion' => 'Se ha realizado revision <b>Revision preventiva</b> en el inmueble <b>' . htmlspecialchars($propertyCode, ENT_QUOTES, 'UTF-8') . '</b> con evidencia <a href=\'' . htmlspecialchars(self::preventiveReviewPublicUrl($reviewId), ENT_QUOTES, 'UTF-8') . '\' class=\'link\' target=\'_blank\'>Ver reporte</a>',
      'valor' => (string) ($fee + $transport),
      'transporte' => (string) $transport,
      'valor_mantenimiento' => (string) $fee,
      'valor_revision' => (string) $fee,
      'valor_otro' => '0',
      'sucursal' => trim((string) ($review['sucursal'] ?? '')),
      'contrato' => trim((string) ($review['contrato'] ?? '')),
      'id_revision_preventiva' => (string) $reviewId,
      'id_contrato' => trim((string) ($review['id_contrato'] ?? '')),
      'id_inmueble' => trim((string) ($review['id_inmueble'] ?? '')),
      'fecha_revision' => $now,
      'fecha_ticket' => trim((string) ($ticket['fecha'] ?? '')),
      'inmueble' => $propertyCode,
      'arrendatario' => trim((string) ($review['arrendatario'] ?? $ticket['arrendatario'] ?? '')),
    ]);
    if (!$payload || !$this->db->insert($table, $payload)) {
      return 0;
    }
    return (int) $this->db->lastInsertId();
  }

  /** @param array<string,mixed> $review @param array<string,mixed> $ticket @param array<string,mixed> $actor */
  private function preventiveReviewEnqueueCreatedNotifications(array $review, array $ticket, array $actor, int $reviewId): int
  {
    $configured = $this->correctiveReviewInternalEmailRecipients('revision_preventiva_creada');
    $recipients = [['name' => trim((string) ($actor['name'] ?? '')), 'email' => trim((string) ($actor['email'] ?? '')), 'role' => 'creador']];
    foreach ($configured as $recipient) {
      $recipients[] = ['name' => $recipient['name'], 'email' => $recipient['email'], 'role' => 'funcionario_configurado'];
    }
    $recipients = $this->correctiveReviewUniqueEmailRecipients($recipients);
    $copies = array_column($configured, 'email');
    $recipients = \SCM\Support\InternalEmailCopies::recipients($recipients, $copies);
    if ($recipients === []) {
      return 0;
    }
    $reviewUrl = self::signedPreventiveReviewPublicUrl($reviewId);
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) ($ticket['_ID'] ?? '');
    $contract = trim((string) ($review['contrato'] ?? ''));
    $subject = 'Revisión preventiva agregada' . ($contract !== '' ? ' del contrato #' . $contract : '') . ($ticketLabel !== '' ? ' - caso #' . $ticketLabel : '');
    $creator = trim((string) ($actor['name'] ?? $review['creador'] ?? ''));
    $queue = new \SCM\Support\EmailQueue($this->db);
    $queued = 0;
    foreach ($recipients as $recipient) {
      $name = trim((string) ($recipient['name'] ?? '')) ?: 'Usuario';
      $role = (string) ($recipient['role'] ?? 'destinatario');
      $content = '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . \SCM\Support\EmailTemplate::e($name) . ':</p>'
        . '<p style="margin:0 0 14px;line-height:1.65;">' . ($role === 'creador'
          ? 'Registraste la revisión preventiva #' . $reviewId . '. Recuerda agregar la cotización si es necesario para notificar al área de mantenimiento.'
          : 'El funcionario <b>' . \SCM\Support\EmailTemplate::e($creator) . '</b> realizó la revisión preventiva #' . $reviewId . ($contract !== '' ? ' del contrato <b>' . \SCM\Support\EmailTemplate::e($contract) . '</b>' : '') . '.') . '</p>'
        . '<p style="margin:0;line-height:1.65;"><b>Daños encontrados:</b> ' . \SCM\Support\EmailTemplate::e((string) ($review['encontro_danos'] ?? '-')) . '.</p>';
      $html = \SCM\Support\EmailTemplate::render($subject, $content, ['buttons' => [['url' => $reviewUrl, 'label' => 'Ver revisión preventiva']]]);
      $queued += $queue->enqueue((string) $recipient['email'], $subject, $html, [
        'source_module' => 'revision_preventiva',
        'internal_action' => 'revision_preventiva_creada',
        'internal_cc' => $copies,
        'destination_name' => $name,
        'dedupe_key' => 'revision-preventiva-creada:' . $reviewId . ':' . $role . ':' . strtolower((string) $recipient['email']),
        'payload' => ['review_url' => $reviewUrl, 'reply_to' => (string) ($actor['email'] ?? '')],
        'meta' => [
          'event' => 'revision_preventiva_creada',
          'role' => $role,
          'id_revision_preventiva' => $reviewId,
          'id_ticket' => $ticketLabel,
          'contrato' => $contract,
          'actor' => $creator,
        ],
      ]);
    }
    return $queued;
  }

  /**
   * Revisión a enviar: la indicada o, si no se indica, la más reciente pendiente de envío.
   *
   * @param array<string,mixed> $context @return array<string,mixed>
   */
  private function preventiveReviewSendTarget(array $context, int $reviewId): array
  {
    if ($reviewId <= 0) {
      foreach ((array) ($context['reviews'] ?? []) as $review) {
        if (!PreventiveReviewChecklist::wasSent($review)) {
          $reviewId = (int) ($review['_ID'] ?? 0);
          break;
        }
      }
      if ($reviewId <= 0) {
        throw new \DomainException(!empty($context['reviews']) ? 'La revisión preventiva de este caso ya fue enviada.' : 'Este caso aún no tiene revisión preventiva para enviar.');
      }
    }
    $review = $this->preventiveReviewTargetRow($context, $reviewId);
    if (PreventiveReviewChecklist::wasSent($review)) {
      throw new \DomainException('La revisión preventiva #' . $reviewId . ' ya fue enviada.');
    }
    return $review;
  }

  /**
   * Replica el formulario JetForm "Enviar revisión preventiva": completa pagos, uso y renovación,
   * marca la revisión como enviada, cierra el caso si se pide y notifica a propietario y arrendatario.
   *
   * @return array<string,mixed>
   */
  private function preventiveReviewSend(int $ticketId, int $reviewId): array
  {
    $schema = new SchemaInspector($this->db);
    $reviewTable = $this->db->table('jet_cct_revision_preventiva');
    $ticketTable = $this->db->table('jet_cct_tickets');
    $context = $this->preventiveReviewContext($ticketId);
    $review = $this->preventiveReviewSendTarget($context, $reviewId);
    $reviewId = (int) $review['_ID'];
    $ticket = $context['ticket'];

    $checklist = $this->preventiveReviewParseChecklist(PreventiveReviewChecklist::sendBlocks());
    $close = $this->correctiveReviewText($_POST['cerrar_ticket'] ?? '');
    if (!in_array($close, ['Si', 'No'], true)) {
      throw new \DomainException('Indica si se cierra el caso con el envío de la revisión.');
    }
    if ($close === 'Si') {
      $closeError = \SCM\Modules\TicketCompletion\CompletionRepository::workflowError($this->db, $schema, $ticketId, true);
      if ($closeError !== '') {
        throw new \DomainException($closeError);
      }
    }
    $targets = array_values(array_intersect(['propietario', 'arrendatario'], array_map('strval', (array) ($_POST['notify'] ?? []))));

    $actor = $this->ticketCompletionActor();
    $actorEmployeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $update = $schema->filterTableData($reviewTable, [
      'recomendaciones' => mb_substr($this->preventiveReviewMultiline($_POST['recomendaciones'] ?? ''), 0, 3000),
      'coordinador' => $actor['name'] ?? Auth::user(),
      'email_coordinador' => $actor['email'] ?? '',
      'celular_coordinador' => $actor['phone'] ?? '',
      'cargo_coordinador' => $actor['cargo'] ?? '',
      'fecha_envio' => $now,
      'se_envio' => 'Si',
      'num_rev' => (string) $reviewId,
      'resultado' => 'Requiere intervencion',
      'estado_nuevo' => 'Inspeccionado',
      'estado_administrativo' => 'Inspeccionado',
      'cct_modified' => $nowSql,
    ] + $checklist);
    if (!$update || $this->db->update($reviewTable, $update, ['_ID' => $reviewId]) < 0) {
      throw new \DomainException('No fue posible marcar la revisión preventiva como enviada.');
    }

    $ticketUpdate = ['fecha_actualizacion' => $now, 'cct_modified' => $nowSql];
    if ($close === 'Si') {
      // Mismos estados que dejaba el formulario de WordPress al cerrar con el envío.
      $ticketUpdate += [
        'estado' => 'Cerrado',
        'estado_administrativo' => 'Finalizado',
        'estado_rev_entrega' => 'Si',
        'estado_inventario' => 'Si',
        'estado_acta_entrega' => 'Si',
        'estado_respuesta_cotizacion_mantenimiento' => '',
        'estado_rev_correctiva' => 'Si',
        'estado_rev_preventiva' => 'Si',
        'estado_cotizacion_mantenimiento' => 'Si',
        'estado_acta_satisfaccion' => 'Si',
      ];
    }
    $ticketUpdate = $schema->filterTableData($ticketTable, $ticketUpdate);
    if ($ticketUpdate) {
      $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
    }

    $this->preventiveReviewHistory(
      $schema,
      $ticketId,
      $reviewId,
      $actor,
      $actorEmployeeId,
      $close === 'Si'
        ? 'Te informamos que se procede a cerrar el caso porque se realizó y se envió correctamente la revisión preventiva y no se encontraron daños.'
        : 'Se ha enviado la revisión preventiva realizada en su inmueble.',
      (string) ($review['id_inmueble'] ?? ''),
      'Se envió la revisión preventiva #' . $reviewId . ' al propietario y al arrendatario.'
    );

    try {
      $queued = $this->preventiveReviewEnqueueSentNotifications(array_merge($review, $update), $ticket, $actor, $reviewId, $targets);
    } catch (\Throwable $notifyError) {
      $queued = 0;
      error_log('[revision-preventiva] envio #' . $reviewId . ': ' . $notifyError->getMessage());
    }

    return [
      'message' => 'Revisión preventiva #' . $reviewId . ' enviada.'
        . ($close === 'Si' ? ' Caso cerrado.' : '')
        . ($queued > 0 ? ' Correos en cola: ' . $queued . '.' : ' No se programaron correos.'),
      'review_id' => (string) $reviewId,
      'review_url' => self::preventiveReviewPublicUrl($reviewId),
    ];
  }

  /**
   * @param array<string,mixed> $review @param array<string,mixed> $ticket @param array<string,mixed> $actor
   * @param array<int,string> $targets contactos del caso elegidos al enviar (propietario/arrendatario)
   */
  private function preventiveReviewEnqueueSentNotifications(array $review, array $ticket, array $actor, int $reviewId, array $targets): int
  {
    $recipients = [];
    if (in_array('propietario', $targets, true)) {
      $recipients[] = ['name' => trim((string) ($review['propietario'] ?? '')), 'email' => trim((string) ($review['email_propietario'] ?? '')), 'role' => 'propietario'];
    }
    if (in_array('arrendatario', $targets, true)) {
      $recipients[] = ['name' => trim((string) ($review['arrendatario'] ?? '')), 'email' => trim((string) ($review['email_arrendatario'] ?? '')), 'role' => 'arrendatario'];
    }
    $recipients[] = ['name' => trim((string) ($review['creador'] ?? '')), 'email' => trim((string) ($review['email_creador'] ?? '')), 'role' => 'creador'];
    $configured = $this->correctiveReviewInternalEmailRecipients('revision_preventiva_enviada');
    foreach ($configured as $recipient) {
      $recipients[] = ['name' => $recipient['name'], 'email' => $recipient['email'], 'role' => 'funcionario_configurado'];
    }
    $recipients = $this->correctiveReviewUniqueEmailRecipients($recipients);
    if ($recipients === []) {
      return 0;
    }
    $copies = array_column($configured, 'email');
    $reviewUrl = self::signedPreventiveReviewPublicUrl($reviewId);
    $ticketLabel = trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) ($ticket['_ID'] ?? '');
    $contract = trim((string) ($review['contrato'] ?? ''));
    $property = trim((string) ($review['inmueble'] ?? ''));
    $address = trim((string) ($review['direccion'] ?? ''));
    $sender = trim((string) ($actor['name'] ?? ''));
    $e = static fn(string $value): string => \SCM\Support\EmailTemplate::e($value);
    $place = ($contract !== '' ? ' identificado con el contrato <b>#' . $e($contract) . '</b>' : '') . ($address !== '' ? ', ubicado en <b>' . $e($address) . '</b>' : '');
    $phone = trim((string) ($actor['phone'] ?? ''));
    $cargo = trim((string) ($actor['cargo'] ?? ''));
    $signature = '<p style="margin:16px 0 0;line-height:1.65;">Cordialmente: <b>' . $e($sender) . '</b>'
      . ($phone !== '' ? ' | <b>' . $e($phone) . '</b>' : '')
      . ($cargo !== '' ? ' - ' . $e($cargo) : '') . '.</p>';
    $queue = new \SCM\Support\EmailQueue($this->db);
    $queued = 0;
    foreach ($recipients as $recipient) {
      $name = trim((string) ($recipient['name'] ?? '')) ?: 'Usuario';
      $role = (string) ($recipient['role'] ?? 'destinatario');
      $isCaseContact = in_array($role, ['propietario', 'arrendatario'], true);
      $subject = $isCaseContact
        ? 'Revisión preventiva del inmueble' . ($property !== '' ? ' #' . $property : '') . ($contract !== '' ? ' con contrato #' . $contract : '')
        : 'Revisión preventiva enviada' . ($contract !== '' ? ' del contrato #' . $contract : '') . ($ticketLabel !== '' ? ' - caso #' . $ticketLabel : '');
      $content = '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . $e($name) . ':</p>'
        . ($isCaseContact
          ? '<p style="margin:0 0 14px;line-height:1.65;">Le informamos que en el inmueble' . $place . ' se llevó a cabo la revisión preventiva correspondiente. Esta revisión se realiza periódicamente conforme a la fecha de inicio del contrato. A continuación encontrará el informe detallado del proceso.</p>' . $signature
          : '<p style="margin:0 0 14px;line-height:1.65;">El funcionario <b>' . $e($sender) . '</b> envió la revisión preventiva #' . $reviewId . ' del inmueble' . $place . '.</p>'
            . '<p style="margin:0;line-height:1.65;"><b>Daños encontrados:</b> ' . $e((string) ($review['encontro_danos'] ?? '-')) . '.</p>');
      $html = \SCM\Support\EmailTemplate::render($subject, $content, ['buttons' => [['url' => $reviewUrl, 'label' => $isCaseContact ? 'Consultar informe de revisión preventiva' : 'Ver revisión preventiva']]]);
      $queued += $queue->enqueue((string) $recipient['email'], $subject, $html, [
        'source_module' => 'revision_preventiva',
        'internal_action' => 'revision_preventiva_enviada',
        'internal_cc' => $isCaseContact ? [] : $copies,
        'destination_name' => $name,
        'dedupe_key' => 'revision-preventiva-enviada:' . $reviewId . ':' . $role . ':' . strtolower((string) $recipient['email']),
        'payload' => ['review_url' => $reviewUrl, 'reply_to' => (string) ($actor['email'] ?? '')],
        'meta' => [
          'event' => 'revision_preventiva_enviada',
          'role' => $role,
          'id_revision_preventiva' => $reviewId,
          'id_ticket' => $ticketLabel,
          'contrato' => $contract,
          'actor' => $sender,
        ],
      ]);
    }
    return $queued;
  }

  /** Respuesta para quien solo puede enviar (sin permiso para editar la revisión). */
  private function renderPreventiveReviewSentNotice(int $reviewId): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    return '<div class="scm-acta scm-corrective-review scm-preventive-review"><section class="scm-corrective-existing"><h3>Revisión preventiva enviada</h3>'
      . '<article><div class="scm-corrective-existing-info"><strong>Revisión #' . $h((string) $reviewId) . '</strong><span>El informe quedó enviado y registrado en el historial del caso.</span></div>'
      . '<div class="scm-corrective-existing-actions"><button type="button" class="scm-acta-button scm-acta-secondary" data-scm-open-iframe data-iframe-url="' . $h(self::preventiveReviewPublicUrl($reviewId)) . '" data-iframe-title="Revisión preventiva #' . $h((string) $reviewId) . '">Ver informe</button></div></article>'
      . '<span data-corrective-review-message aria-live="polite"></span></section></div>';
  }

  /** @param array<string,mixed> $context @param array<string,mixed> $review */
  private function renderPreventiveReviewSendForm(array $context, array $review): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $ticket = (array) ($context['ticket'] ?? []);
    $contract = (array) ($context['contract'] ?? []);
    $reviewId = (int) ($review['_ID'] ?? 0);
    $group = PreventiveReviewChecklist::sendGroup();
    $damagesFound = strcasecmp(trim((string) ($review['encontro_danos'] ?? '')), 'Si') === 0;
    $contacts = [
      'propietario' => ['label' => 'Propietario', 'name' => $this->correctiveReviewFirstText([$review['propietario'] ?? '', $contract['propietario'] ?? '']), 'email' => trim((string) ($review['email_propietario'] ?? ''))],
      'arrendatario' => ['label' => 'Arrendatario', 'name' => $this->correctiveReviewFirstText([$review['arrendatario'] ?? '', $contract['arrendatario'] ?? '']), 'email' => trim((string) ($review['email_arrendatario'] ?? ''))],
    ];
    $summary = [
      'Caso' => '#' . (trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) ($ticket['_ID'] ?? '')),
      'Contrato' => ltrim($this->correctiveReviewFirstText([$review['contrato'] ?? '', $contract['contrato'] ?? '']), '#') ?: '-',
      'Daños encontrados' => trim((string) ($review['encontro_danos'] ?? '')) ?: '-',
      'Hallazgos en checklist' => (string) PreventiveReviewChecklist::findingsCount($review),
    ];
    ob_start();
    ?>
    <div class="scm-acta scm-corrective-review scm-preventive-review">
      <form class="scm-prev-wizard scm-prev-send" data-preventive-send-form data-review-id="<?= $h((string) $reviewId) ?>" autocomplete="off" novalidate>
        <input type="hidden" name="review_id" value="<?= $h((string) $reviewId) ?>">
        <section class="scm-prev-step">
          <header><h3>Enviar revisión preventiva #<?= $h((string) $reviewId) ?></h3><p>Completa la información del contrato y elige a quién notificar. Se enviará el enlace al informe y quedará registrado en el historial del caso.</p></header>
          <div class="scm-prev-summary">
            <?php foreach ($summary as $label => $value): ?><div><span><?= $h($label) ?></span><strong><?= $h($value) ?></strong></div><?php endforeach; ?>
          </div>
          <div class="scm-prev-links">
            <button type="button" class="scm-acta-button scm-acta-secondary" data-scm-open-iframe data-iframe-url="<?= $h(self::preventiveReviewPublicUrl($reviewId)) ?>" data-iframe-title="Revisión preventiva #<?= $h((string) $reviewId) ?>">Ver informe antes de enviar</button>
          </div>
          <div class="scm-prev-group">
            <header><div><h3><?= $h($group['title']) ?></h3><p><?= $h($group['description']) ?></p></div></header>
            <?php foreach ($group['blocks'] as $block): $row = PreventiveReviewChecklist::storedRow($review[$block['key']] ?? ''); $obsField = PreventiveReviewChecklist::observationField($block['key']); ?>
              <fieldset class="scm-prev-block">
                <legend><?= $h($block['label']) ?></legend>
                <?php foreach ($block['questions'] as $index => $question): $field = PreventiveReviewChecklist::questionField($block['key'], $index); $value = $row[$field] ?? ''; $name = 'checklist[' . $block['key'] . '][' . $field . ']'; ?>
                  <div class="scm-prev-question" data-prev-alert="<?= $h($question['alert']) ?>">
                    <span><?= $h($question['label']) ?></span>
                    <div class="scm-prev-choice" role="radiogroup" aria-label="<?= $h($question['label']) ?>">
                      <?php foreach (PreventiveReviewChecklist::answerOptions() as $optValue => $optLabel): ?>
                        <label><input type="radio" name="<?= $h($name) ?>" value="<?= $h($optValue) ?>"<?= $value === $optValue ? ' checked' : '' ?>><span><?= $h($optLabel) ?></span></label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <label class="scm-prev-observation"><span>Observaciones</span>
                  <textarea name="checklist[<?= $h($block['key']) ?>][<?= $h($obsField) ?>]" rows="2" placeholder="Opcional"><?= $h($row[$obsField] ?? '') ?></textarea>
                </label>
              </fieldset>
            <?php endforeach; ?>
          </div>
          <label class="scm-prev-observation"><span>Recomendaciones y/o sugerencias</span><textarea name="recomendaciones" rows="4" placeholder="Observaciones, sugerencias o conclusiones de la revisión realizada"><?= $h((string) ($review['recomendaciones'] ?? '')) ?></textarea></label>
          <fieldset class="scm-prev-block">
            <legend>¿Cerrar el caso? *</legend>
            <p class="scm-prev-muted">Cierra el caso si no se encontró ningún daño; de lo contrario déjalo activo para gestionar la cotización.</p>
            <?php if ($damagesFound): ?><p class="scm-prev-warning">Esta revisión registró daños. Lo normal es dejar el caso activo.</p><?php endif; ?>
            <div class="scm-prev-choice scm-prev-choice--lg" role="radiogroup" aria-label="¿Cerrar el caso?">
              <label><input type="radio" name="cerrar_ticket" value="Si" required><span>Sí, cerrar el caso</span></label>
              <label><input type="radio" name="cerrar_ticket" value="No" required<?= $damagesFound ? ' checked' : '' ?>><span>No, dejarlo activo</span></label>
            </div>
          </fieldset>
          <fieldset class="scm-prev-block">
            <legend>Notificar por correo</legend>
            <?php foreach ($contacts as $key => $contact): $valid = filter_var($contact['email'], FILTER_VALIDATE_EMAIL) !== false; ?>
              <label class="scm-prev-notify"><input type="checkbox" name="notify[]" value="<?= $h($key) ?>"<?= $valid ? ' checked' : ' disabled' ?>> <span><b><?= $h($contact['label']) ?>:</b> <?= $h($contact['name'] ?: '-') ?> · <?= $valid ? $h($contact['email']) : 'sin correo registrado' ?></span></label>
            <?php endforeach; ?>
            <p class="scm-prev-muted">También se avisa al creador de la revisión y a los funcionarios de Notificaciones internas → Revisión preventiva enviada.</p>
          </fieldset>
        </section>
        <footer class="scm-prev-nav">
          <?php if ($this->canUseDashboardAction('preventive_review_manage')): ?><button type="button" class="scm-acta-button scm-acta-secondary" data-preventive-send-cancel>Volver</button><?php endif; ?>
          <span data-corrective-review-message aria-live="polite"></span>
          <button type="submit" class="scm-acta-button">Enviar revisión preventiva</button>
        </footer>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $review */
  private function preventiveReviewSentLabel(array $review): string
  {
    $raw = $review['fecha_envio'] ?? '';
    $ts = is_numeric($raw) ? (int) $raw : (strtotime((string) $raw) ?: 0);
    return $ts > 0 ? date('d/m/Y', $ts) : '';
  }

  /** @param array<string,mixed> $context */
  private function renderPreventiveReviewPanel(array $context, int $editReviewId = 0): string
  {
    $ticket = (array) ($context['ticket'] ?? []);
    $contract = (array) ($context['contract'] ?? []);
    $reviews = (array) ($context['reviews'] ?? []);
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $editReview = [];
    foreach ($reviews as $review) {
      if ($editReviewId > 0 && (int) ($review['_ID'] ?? 0) === $editReviewId) {
        $editReview = $review;
      }
    }
    ob_start();
    ?>
    <div class="scm-acta scm-corrective-review scm-preventive-review">
      <?php if ($reviews && !$editReview): ?>
        <section class="scm-corrective-existing">
          <h3>Revisión preventiva registrada</h3>
          <?php foreach ($reviews as $review): $id = (int) ($review['_ID'] ?? 0); $report = $this->preventiveReviewReport($id); ?>
            <article>
              <div class="scm-corrective-existing-info">
                <strong>Revisión #<?= $h((string) $id) ?></strong>
                <span><?= $h($this->correctiveReviewDateLabel($this->correctiveReviewDisplayDateRaw($review))) ?> · <?= $h((string) ($review['creador'] ?? '')) ?></span>
                <span>Daños: <?= $h((string) ($review['encontro_danos'] ?? '-')) ?> · Hallazgos en checklist: <?= $h((string) PreventiveReviewChecklist::findingsCount($review)) ?></span>
                <span><?= PreventiveReviewChecklist::wasSent($review) ? 'Enviada al propietario y arrendatario' . ($this->preventiveReviewSentLabel($review) !== '' ? ' el ' . $h($this->preventiveReviewSentLabel($review)) : '') : 'Pendiente de envío al propietario y arrendatario' ?></span>
                <span><?= $report ? 'Cobro #' . $h((string) $report['_ID']) . ': $' . $h(number_format((int) ($report['valor'] ?? 0), 0, ',', '.')) . ($this->preventiveReviewReportLocked($report) ? ' (exportado/pagado)' : ' (pendiente)') : 'Sin cobro administrativo registrado' ?></span>
              </div>
              <div class="scm-corrective-existing-actions">
                <button type="button" class="scm-acta-button scm-acta-secondary" data-scm-open-iframe data-iframe-url="<?= $h(self::preventiveReviewPublicUrl($id)) ?>" data-iframe-title="Revisión preventiva #<?= $h((string) $id) ?>">Ver informe</button>
                <?php if (!PreventiveReviewChecklist::wasSent($review) && $this->canUseDashboardAction('preventive_review_send')): ?><button type="button" class="scm-acta-button" data-preventive-send-review="<?= $h((string) $id) ?>">Enviar</button><?php endif; ?>
                <button type="button" class="scm-acta-button scm-acta-secondary" data-preventive-edit-review="<?= $h((string) $id) ?>">Editar</button>
                <button type="button" class="scm-acta-button scm-acta-danger" data-preventive-delete-review="<?= $h((string) $id) ?>">Eliminar</button>
              </div>
            </article>
          <?php endforeach; ?>
          <span data-corrective-review-message aria-live="polite"></span>
        </section>
      <?php else: ?>
        <?= $this->renderPreventiveReviewForm($ticket, $contract, $editReview) ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $ticket @param array<string,mixed> $contract @param array<string,mixed> $review */
  private function renderPreventiveReviewForm(array $ticket, array $contract, array $review): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $editing = $review !== [];
    $reviewId = (int) ($review['_ID'] ?? 0);
    $ticketId = (int) ($ticket['_ID'] ?? 0);
    $charge = $this->preventiveReviewChargeConfig();
    $report = $editing ? $this->preventiveReviewReport($reviewId) : [];
    $reportLocked = $this->preventiveReviewReportLocked($report);
    $selectedTransport = $report ? (int) ($report['transporte'] ?? 0) : (int) max($charge['options']);
    $fee = $report ? (int) ($report['valor_revision'] ?? $report['valor_mantenimiento'] ?? 0) : (int) ($charge['fee'] ?? 0);
    $found = $editing ? (string) ($review['encontro_danos'] ?? 'Si') : '';
    $items = $editing ? $this->correctiveReviewStoredItems($review['evaluacion_de_danos'] ?? []) : [];
    $inventoryId = trim((string) ($contract['id_inventario'] ?? ''));
    $photoAlbum = trim((string) ($contract['registro_fotografico'] ?? ''));
    $groupsByStep = [];
    foreach (PreventiveReviewChecklist::groups() as $group) {
      $groupsByStep[$group['step']][] = $group;
    }
    $steps = [
      'inicio' => 'Datos',
      'inmueble-estructura' => 'Estructura',
      'inmueble-instalaciones' => 'Instalaciones',
      'inmueble-espacios' => 'Espacios',
      'contrato' => 'Contrato',
      'danos' => 'Daños',
      'cobro' => 'Cobro y envío',
    ];
    $summary = [
      'Caso' => '#' . (trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticketId),
      'Contrato' => ltrim($this->correctiveReviewFirstText([$contract['contrato'] ?? '', $ticket['contrato'] ?? '']), '#') ?: '-',
      'Inmueble simi' => $this->correctiveReviewFirstText([$contract['inmueble'] ?? '', $ticket['inmueble'] ?? '']) ?: '-',
      'Dirección' => $this->correctiveReviewFirstText([$contract['direccion'] ?? '', $ticket['direccion'] ?? '']) ?: '-',
      'Propietario' => $this->correctiveReviewFirstText([$contract['propietario'] ?? '', $ticket['propietario'] ?? '']) ?: '-',
      'Arrendatario' => $this->correctiveReviewFirstText([$contract['arrendatario'] ?? '', $ticket['arrendatario'] ?? '']) ?: '-',
    ];
    ob_start();
    ?>
    <form class="scm-prev-wizard" data-preventive-review-form<?= $editing ? ' data-preventive-review-edit' : '' ?> data-preventive-review-version="<?= $h($editing ? hash('sha256', (string) ($review['cct_modified'] ?? '') . $reviewId) : 'new') ?>" autocomplete="off" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="ticket_pk" value="<?= $h((string) $ticketId) ?>">
      <?php if ($editing): ?><input type="hidden" name="review_id" value="<?= $h((string) $reviewId) ?>"><?php endif; ?>
      <ol class="scm-prev-steps" data-prev-steps>
        <?php $n = 0; foreach ($steps as $stepKey => $stepLabel): $n++; ?>
          <li><button type="button" data-prev-goto="<?= $h($stepKey) ?>"<?= $n === 1 ? ' aria-current="step"' : '' ?>><span><?= $n ?></span><?= $h($stepLabel) ?></button></li>
        <?php endforeach; ?>
      </ol>
      <div class="scm-prev-progress" aria-hidden="true"><span data-prev-progress></span></div>

      <section class="scm-prev-step" data-prev-step="inicio">
        <header><h3><?= $editing ? 'Editando revisión preventiva #' . $h((string) $reviewId) : 'Nueva revisión preventiva' ?></h3><p>Revisa los datos del contrato y abre el inventario y el registro fotográfico antes de recorrer el inmueble.</p></header>
        <div class="scm-prev-summary">
          <?php foreach ($summary as $label => $value): ?><div><span><?= $h($label) ?></span><strong><?= $h($value) ?></strong></div><?php endforeach; ?>
        </div>
        <div class="scm-prev-links">
          <?php if ($inventoryId !== ''): ?><button type="button" class="scm-acta-button scm-acta-secondary" data-scm-open-iframe data-iframe-title="Inventario del inmueble" data-iframe-url="https://sucasainmobiliaria.com.co/inventario-inmueble/?numero=<?= $h(rawurlencode($inventoryId)) ?>">Ver inventario</button><?php else: ?><span class="scm-prev-muted">El contrato no tiene inventario vinculado.</span><?php endif; ?>
          <?php if (preg_match('#^https?://#i', $photoAlbum)): ?><button type="button" class="scm-acta-button scm-acta-secondary" data-scm-open-iframe data-iframe-title="Registro fotográfico" data-iframe-url="<?= $h($photoAlbum) ?>">Ver registro fotográfico</button><?php else: ?><span class="scm-prev-muted">El contrato no tiene registro fotográfico.</span><?php endif; ?>
        </div>
      </section>

      <?php foreach (['inmueble-estructura', 'inmueble-instalaciones', 'inmueble-espacios', 'contrato'] as $stepKey): ?>
        <section class="scm-prev-step" data-prev-step="<?= $h($stepKey) ?>" hidden>
          <?php foreach ($groupsByStep[$stepKey] ?? [] as $group): ?>
            <div class="scm-prev-group">
              <header>
                <div><h3><?= $h($group['title']) ?></h3><p><?= $h($group['description']) ?></p></div>
                <?php if ($stepKey !== 'contrato'): ?><button type="button" class="scm-prev-quick" data-prev-all-ok>Todo sin novedad</button><?php endif; ?>
              </header>
              <?php foreach ($group['blocks'] as $block): $row = PreventiveReviewChecklist::storedRow($review[$block['key']] ?? ''); $obsField = PreventiveReviewChecklist::observationField($block['key']); ?>
                <fieldset class="scm-prev-block">
                  <legend><?= $h($block['label']) ?></legend>
                  <?php foreach ($block['questions'] as $index => $question): $field = PreventiveReviewChecklist::questionField($block['key'], $index); $value = $row[$field] ?? ''; $name = 'checklist[' . $block['key'] . '][' . $field . ']'; ?>
                    <?php if (($question['type'] ?? '') === 'text'): ?>
                      <label class="scm-prev-question scm-prev-question--text"><span><?= $h($question['label']) ?></span><input type="text" name="<?= $h($name) ?>" value="<?= $h($value) ?>" maxlength="500" placeholder="Ej: arrendatario y 2 personas más"></label>
                    <?php else: ?>
                      <div class="scm-prev-question" data-prev-alert="<?= $h($question['alert']) ?>">
                        <span><?= $h($question['label']) ?></span>
                        <div class="scm-prev-choice" role="radiogroup" aria-label="<?= $h($question['label']) ?>">
                          <?php foreach (PreventiveReviewChecklist::answerOptions() as $optValue => $optLabel): ?>
                            <label><input type="radio" name="<?= $h($name) ?>" value="<?= $h($optValue) ?>"<?= $value === $optValue ? ' checked' : '' ?>><span><?= $h($optLabel) ?></span></label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <label class="scm-prev-observation"><span><?= $h($block['observations'] ?? 'Observaciones') ?><?= isset($block['observations']) ? ' *' : '' ?></span>
                    <textarea name="checklist[<?= $h($block['key']) ?>][<?= $h($obsField) ?>]" rows="2"<?= isset($block['observations']) ? ' required' : '' ?> placeholder="<?= isset($block['observations']) ? 'Ej: vivienda familiar, local comercial…' : 'Opcional' ?>"><?= $h($row[$obsField] ?? '') ?></textarea>
                  </label>
                </fieldset>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <section class="scm-prev-step" data-prev-step="danos" hidden>
        <header><h3>¿Encontró daños en el inmueble? *</h3><p>Si respondes Sí, registra cada daño con su índice, área, nivel y fotos, igual que en la revisión correctiva.</p></header>
        <div class="scm-prev-choice scm-prev-choice--lg" role="radiogroup" aria-label="¿Encontró daños en el inmueble?">
          <label><input type="radio" name="encontro_danos" value="Si" required data-prev-found<?= $found === 'Si' ? ' checked' : '' ?>><span>Sí, encontré daños</span></label>
          <label><input type="radio" name="encontro_danos" value="No" required data-prev-found<?= $found === 'No' ? ' checked' : '' ?>><span>No se encontraron daños</span></label>
        </div>
        <div data-prev-damages<?= $found === 'Si' ? '' : ' hidden' ?>>
          <div data-corrective-review-items>
            <?php foreach (($items ?: [[]]) as $index => $item): ?>
              <?= $this->renderCorrectiveReviewItem((int) $index, is_array($item) ? $item : []) ?>
            <?php endforeach; ?>
          </div>
          <button type="button" class="scm-acta-button scm-acta-secondary" data-corrective-add-item>Agregar otro daño</button>
        </div>
      </section>

      <section class="scm-prev-step" data-prev-step="cobro" hidden>
        <header><h3>Cobro administrativo</h3><p>Al <?= $editing ? 'guardar' : 'crear' ?> la revisión se registra el cobro en Reportes administrativos (tarifa de revisión + transporte).</p></header>
        <div class="scm-prev-charge">
          <div><span>Tarifa de revisión</span><strong data-prev-fee="<?= $h((string) $fee) ?>"><?= $fee > 0 ? '$' . $h(number_format($fee, 0, ',', '.')) : 'Sin configurar' ?></strong></div>
          <label><span>Transporte</span>
            <select name="transporte" data-prev-transport<?= $reportLocked ? ' disabled' : '' ?>>
              <?php foreach ($charge['options'] as $option): ?><option value="<?= $h((string) $option) ?>"<?= $option === $selectedTransport ? ' selected' : '' ?>><?= $option > 0 ? '$' . $h(number_format($option, 0, ',', '.')) : 'Sin transporte' ?></option><?php endforeach; ?>
              <?php if (!in_array($selectedTransport, $charge['options'], true)): ?><option value="<?= $h((string) $selectedTransport) ?>" selected>$<?= $h(number_format($selectedTransport, 0, ',', '.')) ?></option><?php endif; ?>
            </select>
          </label>
          <div class="scm-prev-charge-total"><span>Total a cobrar</span><strong data-prev-total>$<?= $h(number_format($fee + $selectedTransport, 0, ',', '.')) ?></strong></div>
        </div>
        <?php if ($reportLocked): ?><p class="scm-prev-muted">El cobro ya fue exportado o pagado; no se modificará.</p><?php elseif ($fee <= 0): ?><p class="scm-prev-warning">No está configurada la tarifa de revisión en la configuración del sistema. No se podrá crear la revisión hasta configurarla.</p><?php endif; ?>
        <label class="scm-prev-observation"><span>Recomendaciones generales</span><textarea name="recomendaciones" rows="3" placeholder="Opcional: recomendaciones para el propietario o el arrendatario"><?= $h((string) ($review['recomendaciones'] ?? '')) ?></textarea></label>
        <div class="scm-prev-review" data-prev-review></div>
      </section>

      <footer class="scm-prev-nav">
        <button type="button" class="scm-acta-button scm-acta-secondary" data-prev-back hidden>Anterior</button>
        <?php if ($editing): ?><button type="button" class="scm-acta-button scm-acta-secondary" data-preventive-cancel-edit>Cancelar edición</button><?php endif; ?>
        <span data-corrective-review-message aria-live="polite"></span>
        <button type="button" class="scm-acta-button" data-prev-next>Siguiente</button>
        <button type="submit" class="scm-acta-button" data-prev-submit hidden><?= $editing ? 'Actualizar revisión preventiva' : 'Crear revisión preventiva' ?></button>
      </footer>
    </form>
    <?php
    return (string) ob_get_clean();
  }
}
