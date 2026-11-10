<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\DeliveryReview\DeliveryReviewForm;
use SCM\Modules\Inventory\InventoryBilling;
use SCM\Modules\Inventory\InventoryDraftStore;
use SCM\Modules\Inventory\InventoryInput;
use SCM\Modules\Inventory\InventorySchema;
use SCM\Modules\Inventory\InventorySuggestions;
use SCM\Modules\TicketCompletion\CompletionPolicy;
use SCM\Support\SchemaInspector;

/**
 * Inventario del inmueble nativo en los casos de entrega. Reemplaza los JetForm "Añadir inventario" y "Editar inventario"
 * y guarda en el mismo CCT. Un contrato puede tener varios inventarios cuando entrega más de un inmueble.
 */
trait HandlesInventoryActions
{
  public function ajax_handler_inventory(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('inventory_manage')) {
      $this->jsonFail('No tienes permiso para gestionar inventarios.');
    }
    $ticketId = (int) ($_POST['ticket_pk'] ?? 0);
    $operation = trim((string) ($_POST['operation'] ?? 'read'));
    try {
      if (!$this->canAccessDeliveryReview($ticketId)) {
        http_response_code(403);
        $this->jsonFail('No tienes permiso para gestionar el inventario de este caso.');
      }
      $inventoryId = (int) ($_POST['review_id'] ?? 0);
      $result = [];
      $mode = 'list';
      if ($operation === 'create') {
        $result = $this->inventoryCreate($ticketId);
      } elseif ($operation === 'update') {
        $result = $this->inventoryUpdate($ticketId, $inventoryId);
      } elseif ($operation === 'delete') {
        if (!$this->canUseDashboardAction('inventory_delete')) {
          $this->jsonFail('No tienes permiso para eliminar inventarios.');
        }
        $result = $this->inventoryDelete($ticketId, $inventoryId);
      } elseif ($operation === 'new') {
        $mode = 'new';
      } elseif ($operation === 'edit') {
        $mode = 'edit';
      } elseif ($operation === 'previous') {
        $this->jsonOk(['state' => $this->inventoryPreviousState($ticketId, (int) ($_POST['source_id'] ?? 0))]);
      } elseif ($operation !== 'read') {
        throw new \DomainException('Operación de inventario no válida.');
      }
      $context = $this->inventoryContext($ticketId);
      if ($mode === 'list' && $context['inventories'] === [] && $operation === 'read') {
        $mode = 'new';
      }
      $this->jsonOk($result + ['html' => $this->renderInventoryPanel($context, $mode, $inventoryId)]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }

  /** Autoguardado en servidor: una fila comprimida por funcionario, caso e inventario. */
  public function ajax_handler_inventory_draft(): void
  {
    $this->verifyCsrf();
    if (!$this->canUseDashboardAction('inventory_manage')) {
      $this->jsonFail('No tienes permiso para gestionar inventarios.');
    }
    $ticketId = (int) ($_POST['ticket_pk'] ?? 0);
    if (!$this->canAccessDeliveryReview($ticketId)) {
      http_response_code(403);
      $this->jsonFail('No tienes permiso para este caso.');
    }
    $inventoryId = max(0, (int) ($_POST['review_id'] ?? 0));
    $user = hash('sha256', 'inventario|' . (string) Auth::userId());
    $store = new InventoryDraftStore($this->db);
    try {
      $operation = (string) ($_POST['operation'] ?? 'get');
      if ($operation === 'save') {
        $this->jsonOk($store->save($user, $ticketId, $inventoryId, (string) ($_POST['payload'] ?? ''), (int) ($_POST['revision'] ?? 0)));
      }
      if ($operation === 'delete') {
        $store->delete($user, $ticketId, $inventoryId);
        $this->jsonOk(['status' => 'deleted']);
      }
      $this->jsonOk(['draft' => $store->get($user, $ticketId, $inventoryId)]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }

  private function inventorySchema(): SchemaInspector
  {
    return new SchemaInspector($this->db);
  }

  /** @return array{ticket:array<string,mixed>,contract:array<string,mixed>,property:array<string,mixed>,inventories:list<array<string,mixed>>} */
  private function inventoryContext(int $ticketId): array
  {
    $ticket = $this->correctiveReviewTicket($ticketId);
    if (!$ticket) {
      throw new \DomainException('No se encontró el caso seleccionado.');
    }
    $contract = $this->correctiveReviewContract($ticket);
    $property = $this->correctiveReviewProperty($ticket, $contract);
    $table = $this->db->table('jet_cct_inventario');
    $contractId = $this->correctiveReviewDigits($contract['_ID'] ?? ($ticket['id_contrato'] ?? ''));
    $where = '`id_ticket` = ?' . ($contractId !== '' ? ' OR `id_contrato` = ?' : '');
    $args = $contractId !== '' ? [(string) $ticketId, $contractId] : [(string) $ticketId];
    $inventories = $this->db->getResults("SELECT * FROM `{$table}` WHERE ({$where}) AND COALESCE(`cct_status`, 'publish') = 'publish' ORDER BY `_ID` ASC", $args);
    return ['ticket' => $ticket, 'contract' => $contract, 'property' => $property, 'inventories' => $inventories];
  }

  /** @param array<string,mixed> $context @return array<string,mixed> */
  private function inventoryTarget(array $context, int $inventoryId): array
  {
    foreach ($context['inventories'] as $row) {
      if ((int) ($row['_ID'] ?? 0) === $inventoryId) {
        return $row;
      }
    }
    throw new \DomainException('El inventario no pertenece a este caso o ya no existe.');
  }

  /** @return array<string,mixed> */
  private function inventoryConfig(): array
  {
    $table = $this->db->table('jet_cct_confi_sistema');
    $placeholders = implode(',', array_fill(0, count(InventoryBilling::CONFIG_KEYS), '?'));
    $config = [];
    foreach ($this->db->getResults("SELECT `funcion`, `valor` FROM `{$table}` WHERE `funcion` IN ({$placeholders}) ORDER BY `_ID`", InventoryBilling::CONFIG_KEYS) as $row) {
      $config[(string) $row['funcion']] = $row['valor'] ?? '';
    }
    return $config;
  }

  /** @return array{types:list<string>,categories:list<string>} */
  private function inventoryOptions(): array
  {
    return [
      'types' => DeliveryReviewForm::glossary($this->db, InventorySchema::GLOSSARY_TYPE) ?: ['Nuevo', 'Anterior'],
      'categories' => DeliveryReviewForm::glossary($this->db, InventorySchema::GLOSSARY_CATEGORY) ?: ['No amoblado', 'Amoblado'],
    ];
  }

  /** @return array<string,mixed> */
  private function inventoryInputFromRequest(bool $requirePhotos): array
  {
    $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) {
      throw new \DomainException('No se recibieron los datos del inventario.');
    }
    return InventoryInput::parse($payload, $this->inventoryOptions(), $requirePhotos);
  }

  /** @return array<string,mixed> */
  private function inventoryCreate(int $ticketId): array
  {
    $schema = $this->inventorySchema();
    $table = $this->db->table('jet_cct_inventario');
    $context = $this->inventoryContext($ticketId);
    $ticket = $context['ticket'];
    $contract = $context['contract'];
    $property = $context['property'];
    $this->correctiveReviewValidateContext($ticket, $contract, $property);
    $input = $this->inventoryInputFromRequest(true);
    $columns = $input['columns'];
    // El primer inventario es el del inmueble del contrato; los siguientes son de otros inmuebles entregados en el mismo contrato.
    $principalId = $this->correctiveReviewDigits($contract['id_inventario'] ?? '');
    $isAdditional = $principalId !== '' && $this->inventoryExists($principalId, $context['inventories']);

    $actor = $this->ticketCompletionActor();
    $employeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $branch = $this->preventiveReviewBranch($ticket, $contract);
    $first = fn(array $values): string => $this->correctiveReviewFirstText($values);
    $postId = $isAdditional ? '' : $this->correctiveReviewDigits($first([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '']));
    $reviewId = (string) (array_reverse($this->correctiveReviewSplitIds((string) ($ticket['id_revision_entrega'] ?? '')))[0] ?? '');

    $data = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'fecha' => $now,
      'fecha_entrega' => $now,
      'arrendador' => 'SKC SuCasa Inmobiliaria',
      'se_envio' => 'No',
      'incluir_anexos' => 'Si',
      'creador' => $actor['name'] ?? Auth::user(),
      'email_creador' => $actor['email'] ?? '',
      'celular_creador' => $actor['phone'] ?? '',
      'id_empleado' => $employeeId,
      'coordinador' => $branch['nombre_contractual'] ?? '',
      'email_coordinador' => $branch['correo_contractual'] ?? '',
      'celular_coordinador' => $branch['celular_contractual'] ?? '',
      'sucursal' => $first([$ticket['id_sucursal'] ?? '', $contract['sucursal'] ?? '', $property['sucursal'] ?? '']),
      'id_ticket' => (string) $ticketId,
      'id_inmueble' => $postId,
      'id_contrato' => $this->correctiveReviewDigits($first([$contract['_ID'] ?? '', $ticket['id_contrato'] ?? ''])),
      'id_revision_entrega' => $reviewId,
      'id_propietario' => $first([$ticket['id_propietario'] ?? '', $contract['id_propietario'] ?? '']),
      'id_arrendatario' => $first([$ticket['id_arrendatario'] ?? '', $contract['id_arrendatario'] ?? '']),
      'propietario' => $first([$contract['propietario'] ?? '', $ticket['propietario'] ?? '']),
      'correo_propietario' => $first([$contract['correo_propietario'] ?? '', $ticket['correo_propietario'] ?? '']),
      'celular_propietario' => $first([$contract['celular_propietario'] ?? '', $ticket['celular_propietario'] ?? '']),
      'id_estudio_aseguradora' => $first([$ticket['id_estudio_aseguradora'] ?? '', $contract['id_estudio_aseguradora'] ?? '']),
      'numero_solicitud' => $first([$ticket['numero_solicitud'] ?? '', $contract['numero_solicitud'] ?? '']),
    ] + $columns;

    $pdo = $this->db->pdo();
    $reportNote = '';
    try {
      $pdo->beginTransaction();
      $insert = $schema->filterTableData($table, $data);
      if (!$this->db->insert($table, $insert)) {
        throw new \DomainException('No fue posible guardar el inventario.');
      }
      $inventoryId = (int) $this->db->lastInsertId();
      if ($inventoryId <= 0) {
        throw new \DomainException('El inventario se guardó sin identificador válido.');
      }
      if (!$isAdditional) {
        $this->inventorySyncPrincipal($schema, $inventoryId, $columns, $ticket, $contract, $actor, $now);
      }
      if ((string) $columns['tipo_inventario'] === InventorySchema::NEW_TYPE) {
        $this->inventoryHistory($schema, $ticketId, $inventoryId, $actor, $employeeId, 'Se ha elaborado el inventario antes de la ocupación de acuerdo al proceso de entrega. Procederemos a enviarlo al momento de la entrega.', $postId, 'Se ha realizado el inventario inicial a su inmueble.');
        $reportNote = $this->inventoryCreateReport($schema, $inventoryId, $insert + $data, $input['with_transport'], $ticket, $actor, $employeeId, $reviewId, $now, $nowSql);
      }
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($error instanceof \DomainException) {
        throw $error;
      }
      error_log('[inventario] create ticket #' . $ticketId . ': ' . $error->getMessage());
      throw new \DomainException('No fue posible guardar el inventario.');
    }
    $this->inventoryClearDraft($ticketId, 0);
    return ['message' => 'Inventario #' . $inventoryId . ' guardado.' . $reportNote, 'review_id' => (string) $inventoryId];
  }

  /** @param list<array<string,mixed>> $inventories */
  private function inventoryExists(string $id, array $inventories): bool
  {
    foreach ($inventories as $row) {
      if ((string) ($row['_ID'] ?? '') === $id) {
        return true;
      }
    }
    return false;
  }

  /**
   * Lo que hacía el JetForm con el contrato, el caso y la ficha del inmueble. Solo aplica al inventario principal.
   *
   * @param array<string,mixed> $columns @param array<string,mixed> $ticket @param array<string,mixed> $contract @param array<string,mixed> $actor
   */
  private function inventorySyncPrincipal(SchemaInspector $schema, int $inventoryId, array $columns, array $ticket, array $contract, array $actor, int $now): void
  {
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractId = (int) ($contract['_ID'] ?? 0);
    if ($contractId > 0 && $schema->tableExists($contractTable)) {
      $update = array_intersect_key($columns, array_flip(['direccion', 'arrendatario', 'correo_arrendatario', 'celular_arrendatario', 'delegado', 'correo_delegado', 'celular_delegado', 'luz', 'agua', 'gas', 'medidor_luz', 'medidor_agua', 'medidor_gas', 'tiene_delegado', 'registro_fotografico', 'servicios_publicos', 'tiene_parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero', 'tiene_deposito', 'depositos', 'fecha_linderos', 'conjunto_lin', 'torre_lin', 'inmueble_lin', 'parqueadero_lin', 'deposito_lin', 'acuerdos_precontractuales', 'acuerdos_precontractuales_pro', 'acuerdos_precontractuales_inm', 'acuerdos_precontractuales_arre', 'acuerdos_precontractuales_ext', 'acuerdos_precontractuales_co']));
      $update['documento_arrendatario'] = (string) ($columns['cedula_arrendatario'] ?? '');
      $update['documento_delegado'] = (string) ($columns['cedula_delegado'] ?? '');
      if ($inventoryId > 0) {
        $update['id_inventario'] = (string) $inventoryId;
      }
      $update = $schema->filterTableData($contractTable, $update);
      if ($update) {
        $this->db->update($contractTable, $update, ['_ID' => $contractId]);
      }
    }
    if ($inventoryId > 0) {
      $ticketTable = $this->db->table('jet_cct_tickets');
      // Mismo cambio del JetForm con el estado vigente: los "En ejecucion por …" se retiraron (docs/ejecutado-caso.md).
      $ticketUpdate = $schema->filterTableData($ticketTable, [
        'id_inventario' => (string) $inventoryId,
        'estado_inventario' => 'Si',
        'estado_administrativo' => CompletionPolicy::EXECUTION_STATE,
        'fecha_actualizacion' => $now,
        'nombre_empleado' => $actor['name'] ?? Auth::user(),
        'correo_empleado' => $actor['email'] ?? '',
        'celular_empleado' => $actor['phone'] ?? '',
      ]);
      if ($ticketUpdate) {
        $this->db->update($ticketTable, $ticketUpdate, ['_ID' => (int) ($ticket['_ID'] ?? 0)]);
      }
    }
    $postId = (int) $this->correctiveReviewDigits($this->correctiveReviewFirstText([$ticket['id_inmueble'] ?? '', $contract['id_inmueble'] ?? '']));
    if ($postId > 0) {
      $meta = [
        'direccion-fisica' => (string) ($columns['direccion'] ?? ''), 'luz' => (string) ($columns['luz'] ?? ''), 'agua' => (string) ($columns['agua'] ?? ''), 'gas' => (string) ($columns['gas'] ?? ''),
        'tiene-parqueadero' => (string) ($columns['tiene_parqueadero'] ?? ''), 'tipo-parqueadero' => (string) ($columns['tipo_parqueadero'] ?? ''), 'parqueaderos' => (string) ($columns['parqueaderos'] ?? ''),
        'parqueadero' => (string) ($columns['numero_parqueadero'] ?? ''), 'tiene-deposito' => (string) ($columns['tiene_deposito'] ?? ''), 'depositos' => (string) ($columns['depositos'] ?? ''),
        'servicios-publicos' => DeliveryReviewForm::values($columns['servicios_publicos'] ?? ''),
      ];
      if ($inventoryId > 0) {
        $meta['id_inventario'] = (string) $inventoryId;
      }
      foreach ($meta as $key => $value) {
        $this->deliveryReviewSetPostMeta($postId, $key, $value);
      }
    }
  }

  /**
   * Reporte administrativo del inventario tipo Nuevo: tarifa vigente, doble si es amoblado o de varios pisos, máximo 2 por caso.
   *
   * @param array<string,mixed> $inventory @param array<string,mixed> $ticket @param array<string,mixed> $actor
   */
  private function inventoryCreateReport(SchemaInspector $schema, int $inventoryId, array $inventory, bool $withTransport, array $ticket, array $actor, string $employeeId, string $reviewId, int $now, string $nowSql): string
  {
    $table = $this->db->table('jet_cct_reportes_administrativos');
    if (!$schema->tableExists($table)) {
      return '';
    }
    $ticketId = (int) ($ticket['_ID'] ?? 0);
    $count = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE `id_ticket` = ? AND `categoria` = ?", [(string) $ticketId, InventoryBilling::REPORT_CATEGORY]);
    if ($count >= InventoryBilling::MAX_REPORTS_PER_CASE) {
      return ' No se generó reporte administrativo: el caso ya tiene ' . InventoryBilling::MAX_REPORTS_PER_CASE . '.';
    }
    $amounts = InventoryBilling::amounts($this->inventoryConfig(), (string) ($inventory['categoria_inventario'] ?? ''), $inventory['cantidad_pisos'] ?? 1, $withTransport);
    if ($amounts['base'] <= 0) {
      throw new \DomainException('No se pudo calcular la tarifa del servicio. Revisa salario, días de trabajo y porcentaje en la configuración del sistema.');
    }
    $text = static fn(string $key): string => trim((string) ($inventory[$key] ?? ''));
    $payload = $schema->filterTableData($table, [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'id_ticket' => (string) $ticketId,
      'id_empleado' => $employeeId,
      'creador' => $actor['name'] ?? Auth::user(),
      'fecha' => $now,
      'fecha_revision' => $now,
      'fecha_ticket' => trim((string) ($ticket['fecha'] ?? '')),
      'fue_pagado' => 'No',
      'exportado' => 'No',
      'categoria' => InventoryBilling::REPORT_CATEGORY,
      'descripcion' => InventoryBilling::description($text('contrato'), $text('numero_solicitud')),
      'valor' => (string) $amounts['total'],
      'transporte' => (string) $amounts['transporte'],
      'valor_mantenimiento' => (string) $amounts['servicio'],
      'valor_revision' => (string) $amounts['servicio'],
      'sucursal' => $text('sucursal'),
      'contrato' => $text('contrato'),
      'inmueble' => $text('inmueble'),
      'arrendatario' => $text('arrendatario'),
      'id_contrato' => $text('id_contrato'),
      'id_inmueble' => $text('id_inmueble'),
      'id_rev_entrega' => $reviewId,
      'id_estudio_aseguradora' => $text('id_estudio_aseguradora'),
      'numero_solicitud' => $text('numero_solicitud'),
    ]);
    if (!$this->db->insert($table, $payload)) {
      throw new \DomainException('No fue posible crear el reporte administrativo del inventario.');
    }
    $reportId = (int) $this->db->lastInsertId();
    $inventoryTable = $this->db->table('jet_cct_inventario');
    $link = $schema->filterTableData($inventoryTable, ['id_reporte_administrativo' => (string) $reportId, 'reporte_realizado' => 'Si']);
    if ($link) {
      $this->db->update($inventoryTable, $link, ['_ID' => $inventoryId]);
    }
    $ticketTable = $this->db->table('jet_cct_tickets');
    $ticketUpdate = $schema->filterTableData($ticketTable, ['reportes' => (string) ($count + 1), 'tuvo_reporte' => 'Si']);
    if ($ticketUpdate) {
      $this->db->update($ticketTable, $ticketUpdate, ['_ID' => $ticketId]);
    }
    return ' Reporte administrativo #' . $reportId . ' por ' . $this->format_cop_currency($amounts['total']) . ($amounts['doble'] ? ' (servicio doble)' : '') . '.';
  }

  /** @return array<string,mixed> */
  private function inventoryUpdate(int $ticketId, int $inventoryId): array
  {
    $schema = $this->inventorySchema();
    $table = $this->db->table('jet_cct_inventario');
    $context = $this->inventoryContext($ticketId);
    $row = $this->inventoryTarget($context, $inventoryId);
    if (!hash_equals((string) ($row['cct_modified'] ?? ''), (string) ($_POST['version'] ?? ''))) {
      throw new \DomainException('Otro funcionario modificó este inventario mientras lo editabas. Vuelve a abrirlo; tu borrador sigue guardado.');
    }
    $input = $this->inventoryInputFromRequest(false);
    $columns = $input['columns'];
    $actor = $this->ticketCompletionActor();
    $employeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
    $now = time();
    $nowSql = date('Y-m-d H:i:s', max($now, (strtotime((string) ($row['cct_modified'] ?? '')) ?: 0) + 1));
    $pdo = $this->db->pdo();
    $reportNote = '';
    try {
      $pdo->beginTransaction();
      $this->db->update($table, $schema->filterTableData($table, ['cct_modified' => $nowSql, 'id_empleado' => $employeeId] + $columns), ['_ID' => $inventoryId]);
      if ((string) ($context['contract']['id_inventario'] ?? '') === (string) $inventoryId) {
        $this->inventorySyncPrincipal($schema, 0, $columns, $context['ticket'], $context['contract'], $actor, $now);
      }
      $reportNote = $this->inventoryAdjustReport($schema, $row, $columns, $input['with_transport'], $nowSql);
      $this->inventoryHistory($schema, $ticketId, $inventoryId, $actor, $employeeId, 'Se ha editado el inventario #' . $inventoryId . '.', '', '');
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($error instanceof \DomainException) {
        throw $error;
      }
      error_log('[inventario] update #' . $inventoryId . ': ' . $error->getMessage());
      throw new \DomainException('No fue posible actualizar el inventario.');
    }
    $this->inventoryClearDraft($ticketId, $inventoryId);
    return ['message' => 'Inventario #' . $inventoryId . ' actualizado.' . $reportNote, 'review_id' => (string) $inventoryId];
  }

  /**
   * Recalcula el reporte si cambió lo que define el cobro. Uno ya exportado o pagado no se toca.
   *
   * @param array<string,mixed> $row @param array<string,mixed> $columns
   */
  private function inventoryAdjustReport(SchemaInspector $schema, array $row, array $columns, bool $withTransport, string $nowSql): string
  {
    $reportId = (int) ($row['id_reporte_administrativo'] ?? 0);
    $table = $this->db->table('jet_cct_reportes_administrativos');
    if ($reportId <= 0 || !$schema->tableExists($table)) {
      return '';
    }
    $report = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$reportId]);
    if (!$report) {
      return '';
    }
    $amounts = InventoryBilling::amounts($this->inventoryConfig(), (string) ($columns['categoria_inventario'] ?? ''), $columns['cantidad_pisos'] ?? 1, $withTransport);
    if ((int) ($report['valor'] ?? 0) === $amounts['total'] || $amounts['base'] <= 0) {
      return '';
    }
    if (strcasecmp((string) ($report['exportado'] ?? ''), 'Si') === 0 || strcasecmp((string) ($report['fue_pagado'] ?? ''), 'Si') === 0) {
      return ' El reporte administrativo #' . $reportId . ' ya fue exportado o pagado, por eso no se recalculó.';
    }
    $this->db->update($table, $schema->filterTableData($table, [
      'valor' => (string) $amounts['total'], 'transporte' => (string) $amounts['transporte'],
      'valor_mantenimiento' => (string) $amounts['servicio'], 'valor_revision' => (string) $amounts['servicio'], 'cct_modified' => $nowSql,
    ]), ['_ID' => $reportId]);
    return ' Reporte administrativo #' . $reportId . ' recalculado a ' . $this->format_cop_currency($amounts['total']) . '.';
  }

  /** @return array<string,mixed> */
  private function inventoryDelete(int $ticketId, int $inventoryId): array
  {
    $schema = $this->inventorySchema();
    $table = $this->db->table('jet_cct_inventario');
    $context = $this->inventoryContext($ticketId);
    $row = $this->inventoryTarget($context, $inventoryId);
    $actor = $this->ticketCompletionActor();
    $employeeId = $this->correctiveReviewFirstText([$actor['employee_id'] ?? '', Auth::userId()]);
    $nowSql = date('Y-m-d H:i:s');
    $note = '';
    $pdo = $this->db->pdo();
    try {
      $pdo->beginTransaction();
      $this->db->update($table, $schema->filterTableData($table, ['cct_status' => 'trash', 'cct_modified' => $nowSql]), ['_ID' => $inventoryId]);
      // Si era el principal, otro inventario vigente del contrato toma su lugar.
      $remaining = array_values(array_filter($context['inventories'], static fn(array $item): bool => (int) ($item['_ID'] ?? 0) !== $inventoryId));
      $next = $remaining !== [] ? (string) $remaining[count($remaining) - 1]['_ID'] : '';
      $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
      if ((string) ($context['contract']['id_inventario'] ?? '') === (string) $inventoryId && $schema->tableExists($contractTable)) {
        $this->db->update($contractTable, $schema->filterTableData($contractTable, ['id_inventario' => $next]), ['_ID' => (int) $context['contract']['_ID']]);
      }
      if ((string) ($context['ticket']['id_inventario'] ?? '') === (string) $inventoryId) {
        $ticketTable = $this->db->table('jet_cct_tickets');
        $this->db->update($ticketTable, $schema->filterTableData($ticketTable, ['id_inventario' => $next, 'estado_inventario' => $next !== '' ? 'Si' : '']), ['_ID' => $ticketId]);
      }
      $reportId = (int) ($row['id_reporte_administrativo'] ?? 0);
      $reports = $this->db->table('jet_cct_reportes_administrativos');
      if ($reportId > 0 && $schema->tableExists($reports)) {
        $report = $this->db->getRow("SELECT `exportado`, `fue_pagado` FROM `{$reports}` WHERE `_ID` = ? LIMIT 1", [$reportId]);
        if ($report && strcasecmp((string) ($report['exportado'] ?? ''), 'Si') !== 0 && strcasecmp((string) ($report['fue_pagado'] ?? ''), 'Si') !== 0) {
          $this->db->delete($reports, ['_ID' => $reportId]);
          $note = ' Se eliminó su reporte administrativo #' . $reportId . '.';
        } elseif ($report) {
          $note = ' Su reporte administrativo #' . $reportId . ' ya fue exportado o pagado y se conserva.';
        }
      }
      $this->inventoryHistory($schema, $ticketId, $inventoryId, $actor, $employeeId, 'Se ha eliminado el inventario #' . $inventoryId . ' del caso.' . $note, trim((string) ($row['id_inmueble'] ?? '')), 'Se eliminó el inventario #' . $inventoryId . ' del inmueble.');
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[inventario] delete #' . $inventoryId . ': ' . $error->getMessage());
      throw new \DomainException('No fue posible eliminar el inventario.');
    }
    $this->inventoryClearDraft($ticketId, $inventoryId);
    return ['message' => 'Inventario #' . $inventoryId . ' eliminado.' . $note];
  }

  private function inventoryHistory(SchemaInspector $schema, int $ticketId, int $inventoryId, array $actor, string $employeeId, string $ticketMessage, string $propertyId, string $propertyMessage): void
  {
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $common = ['cct_status' => 'publish', 'cct_author_id' => $employeeId, 'cct_created' => $nowSql, 'cct_modified' => $nowSql, 'id_ticket' => (string) $ticketId, 'fecha' => $now, 'id_empleado' => $employeeId];
    $ticketTable = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($ticketTable)) {
      $payload = $schema->filterTableData($ticketTable, $common + [
        'nombre' => $actor['name'] ?? Auth::user(), 'correo' => $actor['email'] ?? '', 'celular' => $actor['phone'] ?? '',
        'respuesta' => '<p>' . htmlspecialchars($ticketMessage, ENT_QUOTES, 'UTF-8') . '</p>', 'id_inventario' => (string) $inventoryId,
      ]);
      if ($payload) {
        $this->db->insert($ticketTable, $payload);
      }
    }
    $propertyTable = $this->db->table('jet_cct_historial_del_inmueble');
    if ($propertyMessage !== '' && $propertyId !== '' && $schema->tableExists($propertyTable)) {
      $payload = $schema->filterTableData($propertyTable, $common + [
        'id_inmueble' => $propertyId, 'tipo_reporte' => 'Revision de entrega', 'observacion' => $propertyMessage, 'funcionario' => $actor['name'] ?? Auth::user(),
      ]);
      if ($payload) {
        $this->db->insert($propertyTable, $payload);
      }
    }
  }

  private function inventoryClearDraft(int $ticketId, int $inventoryId): void
  {
    try {
      (new InventoryDraftStore($this->db))->delete(hash('sha256', 'inventario|' . (string) Auth::userId()), $ticketId, $inventoryId);
    } catch (\Throwable $error) {
      error_log('[inventario] borrador: ' . $error->getMessage());
    }
  }

  /** Elementos de otro inventario del mismo inmueble, para no empezar desde cero. @return array<string,mixed> */
  private function inventoryPreviousState(int $ticketId, int $sourceId): array
  {
    $context = $this->inventoryContext($ticketId);
    $allowed = $this->inventoryPreviousOptions($context);
    if (!isset($allowed[$sourceId])) {
      throw new \DomainException('Ese inventario no se puede usar como base para este caso.');
    }
    $row = $this->db->getRow("SELECT * FROM `{$this->db->table('jet_cct_inventario')}` WHERE `_ID` = ? LIMIT 1", [$sourceId]);
    $state = $this->inventoryState((array) $row);
    return ['sections' => $state['sections'], 'furniture' => $state['furniture']];
  }

  /** @param array<string,mixed> $context @return array<int,string> */
  private function inventoryPreviousOptions(array $context): array
  {
    $property = $this->correctiveReviewDigits($this->correctiveReviewFirstText([$context['ticket']['id_inmueble'] ?? '', $context['contract']['id_inmueble'] ?? '']));
    $out = [];
    foreach ($context['inventories'] as $row) {
      $out[(int) $row['_ID']] = 'Inventario #' . $row['_ID'] . ' · este contrato';
    }
    if ($property !== '') {
      $table = $this->db->table('jet_cct_inventario');
      foreach ($this->db->getResults("SELECT `_ID`, `fecha`, `tipo_inventario` FROM `{$table}` WHERE `id_inmueble` = ? AND COALESCE(`cct_status`, 'publish') = 'publish' ORDER BY `_ID` DESC LIMIT 8", [$property]) as $row) {
        $date = (int) ($row['fecha'] ?? 0) > 0 ? date('d/m/Y', (int) $row['fecha']) : 'sin fecha';
        $out[(int) $row['_ID']] = 'Inventario #' . $row['_ID'] . ' · ' . $date . ' · ' . trim((string) ($row['tipo_inventario'] ?? ''));
      }
    }
    return $out;
  }

  /** Inventario guardado → estado del formulario. @param array<string,mixed> $row @return array<string,mixed> */
  private function inventoryState(array $row): array
  {
    $data = [];
    foreach (['tipo_inventario', 'categoria_inventario', 'contrato', 'inmueble', 'direccion', 'tipo_de_inmueble', 'tipo_negocio', 'destinacion', 'registro_fotografico', 'tiene_parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero', 'tiene_deposito', 'depositos', 'arrendatario', 'cedula_arrendatario', 'correo_arrendatario', 'celular_arrendatario', 'tiene_delegado', 'delegado', 'cedula_delegado', 'correo_delegado', 'celular_delegado', 'cantidad_habitaciones', 'cantidad_banos', 'observaciones', 'luz', 'agua', 'gas', 'medidor_luz', 'medidor_agua', 'medidor_gas'] as $key) {
      $data[$key] = html_entity_decode(trim((string) ($row[$key] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    foreach (InventorySchema::agreements() as $column) {
      $data[$column] = trim((string) ($row[$column] ?? ''));
    }
    if (str_starts_with($data['tipo_de_inmueble'], 'a:')) {
      $data['tipo_de_inmueble'] = '';
    }
    $data['cantidad_pisos'] = (string) InventorySchema::floors($row['cantidad_pisos'] ?? 1);
    $ts = (int) ($row['fecha_linderos'] ?? 0);
    $data['fecha_linderos'] = $ts > 0 ? date('Y-m-d', $ts) : date('Y-m-d');
    $data['servicios'] = DeliveryReviewForm::values($row['servicios_publicos'] ?? '');
    $data['acuerdos'] = DeliveryReviewForm::values($row['acuerdos_precontractuales'] ?? '');

    $boundaries = [];
    foreach (InventorySchema::boundaries() as $column => $group) {
      $boundaries[$column] = [];
      foreach (DeliveryReviewForm::rows($row[$column] ?? '') as $stored) {
        $item = ['num' => trim((string) ($stored['num_parqueadero'] ?? ''))];
        foreach ($group['sides'] as $side) {
          $item[$side] = trim((string) ($stored[$side . '_' . $group['suffix']] ?? ''));
        }
        $boundaries[$column][] = $item;
      }
    }

    $sections = [];
    $furniture = [];
    $roomMax = 0;
    $bathMax = 0;
    foreach (InventorySchema::sections() as $key => $section) {
      $rows = InventorySchema::rowsFromStored($row[$key] ?? '', $section['suffix']);
      $sections[$key] = [
        'rows' => $rows,
        'floor' => $section['floor'] !== '' ? trim((string) ($row[$section['floor']] ?? '')) : '',
        'details' => $section['details'] !== '' ? trim((string) ($row[$section['details']] ?? '')) : '',
      ];
      $furniture[$key] = InventorySchema::rowsFromStored($row[$section['furniture']] ?? '', $section['furniture_suffix']);
      if ($rows !== [] || $furniture[$key] !== []) {
        if ($section['group'] === 'habitacion') $roomMax = max($roomMax, $section['index']);
        if ($section['group'] === 'bano') $bathMax = max($bathMax, $section['index']);
      }
    }
    $data['cantidad_habitaciones'] = (string) max((int) $data['cantidad_habitaciones'], $roomMax);
    $data['cantidad_banos'] = (string) max((int) $data['cantidad_banos'], $bathMax);
    return ['data' => $data, 'boundaries' => $boundaries, 'sections' => $sections, 'furniture' => $furniture];
  }

  /** Inventario nuevo: lo que ya se conoce del contrato y del inmueble, igual que los valores por defecto del JetForm. */
  private function inventoryDefaults(array $context, bool $additional): array
  {
    $ticket = $context['ticket'];
    $contract = $context['contract'];
    $property = $context['property'];
    $base = $this->deliveryReviewDefaults($ticket, $contract, $property);
    $first = fn(array $values): string => $this->correctiveReviewFirstText($values);
    $row = [
      'contrato' => ltrim($first([$contract['contrato'] ?? '', $ticket['contrato'] ?? '']), '#'),
      'arrendatario' => $first([$contract['arrendatario'] ?? '', $ticket['arrendatario'] ?? '']),
      'cedula_arrendatario' => $first([$contract['documento_arrendatario'] ?? '']),
      'correo_arrendatario' => $first([$contract['correo_arrendatario'] ?? '', $ticket['correo_arrendatario'] ?? '']),
      'celular_arrendatario' => $first([$contract['celular_arrendatario'] ?? '', $ticket['celular_arrendatario'] ?? '']),
      'servicios_publicos' => $base['servicios_publicos'] ?? '',
      'luz' => $first([$contract['luz'] ?? '']), 'agua' => $first([$contract['agua'] ?? '']), 'gas' => $first([$contract['gas'] ?? '']),
      'medidor_luz' => $first([$contract['medidor_luz'] ?? '']), 'medidor_agua' => $first([$contract['medidor_agua'] ?? '']), 'medidor_gas' => $first([$contract['medidor_gas'] ?? '']),
      'acuerdos_precontractuales' => $contract['acuerdos_precontractuales'] ?? '',
      'registro_fotografico' => $additional ? '' : ($base['registro_fotografico'] ?? ''),
      'tipo_inventario' => InventorySchema::NEW_TYPE,
      'categoria_inventario' => 'No amoblado',
    ];
    foreach (InventorySchema::agreements() as $column) {
      $row[$column] = $contract[$column] ?? '';
    }
    if (!$additional) {
      $reviewFloors = $this->inventoryReviewFloors($ticket);
      $row += [
        'inmueble' => $first([$contract['inmueble'] ?? '', $ticket['inmueble'] ?? '']),
        'direccion' => $first([$contract['direccion'] ?? '', $ticket['direccion'] ?? '', $property['direccion'] ?? '']),
        'tipo_de_inmueble' => $first([$property['tipo_inmueble'] ?? '', $ticket['tipo_inmueble'] ?? '', $contract['tipo_inmueble'] ?? '']),
        'tipo_negocio' => $first([$property['tipo_negocio'] ?? '', $ticket['tipo_negocio'] ?? '', $contract['gestion_inmueble'] ?? '']),
        'destinacion' => $first([$property['destinacion'] ?? '', $ticket['destinacion'] ?? '', $contract['destinacion_inmueble'] ?? '']),
        'tiene_parqueadero' => $base['tiene_parqueadero'] ?? '', 'tipo_parqueadero' => $base['tipo_parqueadero'] ?? '', 'parqueaderos' => $base['parqueaderos'] ?? '',
        'numero_parqueadero' => $base['numero_parqueadero'] ?? '', 'tiene_deposito' => $base['tiene_deposito'] ?? '', 'depositos' => $base['depositos'] ?? '',
        'cantidad_habitaciones' => $base['habitaciones'] ?? '', 'cantidad_banos' => $base['banos'] ?? '', 'cantidad_pisos' => $reviewFloors,
        'fecha_linderos' => $contract['fecha_linderos'] ?? '', 'conjunto_lin' => $contract['conjunto_lin'] ?? '', 'torre_lin' => $contract['torre_lin'] ?? '',
        'inmueble_lin' => $contract['inmueble_lin'] ?? '', 'parqueadero_lin' => $contract['parqueadero_lin'] ?? '', 'deposito_lin' => $contract['deposito_lin'] ?? '',
      ];
    }
    return $row;
  }

  /** @param array<string,mixed> $ticket */
  private function inventoryReviewFloors(array $ticket): string
  {
    $ids = $this->correctiveReviewSplitIds((string) ($ticket['id_revision_entrega'] ?? ''));
    $id = (int) (end($ids) ?: 0);
    if ($id <= 0) {
      return '1';
    }
    $value = $this->db->getVar("SELECT `piso` FROM `{$this->db->table('jet_cct_revision_entrega')}` WHERE `_ID` = ? LIMIT 1", [$id]);
    return (string) InventorySchema::floors($value ?? 1);
  }

  /** @return array<string,mixed> */
  private function inventoryCatalog(): array
  {
    $profiles = [];
    foreach (['', 'Apartamento', 'Casa', 'Apartaestudio', 'Casa lote', 'Lote', 'Local', 'Oficina', 'Consultorio', 'Hotel', 'Bodega'] as $type) {
      $profiles[$type] = InventorySchema::profile($type);
    }
    $sections = [];
    foreach (InventorySchema::sections() as $key => $section) {
      $sections[] = ['key' => $key, 'label' => $section['label'], 'group' => $section['group'], 'index' => $section['index'], 'glossary' => $section['glossary'] > 0 ? DeliveryReviewForm::glossary($this->db, $section['glossary']) : [], 'floor' => $section['floor'] !== ''];
    }
    $boundaries = [];
    foreach (InventorySchema::boundaries() as $column => $group) {
      $boundaries[] = ['key' => $column] + $group;
    }
    $options = $this->inventoryOptions();
    return [
      'sections' => $sections,
      'boundaries' => $boundaries,
      'profiles' => $profiles,
      'types' => $options['types'],
      'categories' => $options['categories'],
      'states' => DeliveryReviewForm::glossary($this->db, InventorySchema::GLOSSARY_STATE) ?: ['Bueno', 'Regular', 'Malo'],
      'parking' => DeliveryReviewForm::glossary($this->db, InventorySchema::GLOSSARY_PARKING),
      'services' => DeliveryReviewForm::glossary($this->db, InventorySchema::GLOSSARY_SERVICES) ?: ['Agua', 'Gas', 'Energia'],
      'agreements' => InventorySchema::agreements(),
      'suggestions' => (new InventorySuggestions($this->db, SCM_STORAGE_PATH . '/data/inventory-suggestions.json'))->all(),
    ];
  }

  /** @param array<string,mixed> $context */
  private function renderInventoryPanel(array $context, string $mode, int $inventoryId): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $inventories = $context['inventories'];
    $canDelete = $this->canUseDashboardAction('inventory_delete');
    ob_start();
    ?>
    <div class="scm-acta scm-corrective-review scm-preventive-review scm-inventory-review">
      <?php if ($mode === 'list'): ?>
        <section class="scm-corrective-existing">
          <h3>Inventarios del contrato</h3>
          <?php foreach ($inventories as $row): $id = (int) $row['_ID']; $principal = (string) ($context['contract']['id_inventario'] ?? '') === (string) $id; ?>
            <article>
              <div class="scm-corrective-existing-info">
                <strong>Inventario #<?= $h((string) $id) ?><?= $principal ? ' · principal' : '' ?></strong>
                <span><?= $h($this->correctiveReviewDateLabel($this->correctiveReviewDisplayDateRaw($row))) ?> · <?= $h((string) ($row['creador'] ?? '')) ?></span>
                <span><?= $h(trim((string) ($row['tipo_inventario'] ?? ''))) ?> · <?= $h(trim((string) ($row['categoria_inventario'] ?? ''))) ?> · Inmueble <?= $h(trim((string) ($row['inmueble'] ?? '')) ?: '-') ?></span>
                <span><?= $h(trim((string) ($row['direccion'] ?? ''))) ?></span>
              </div>
              <div class="scm-corrective-existing-actions">
                <button type="button" class="scm-acta-button scm-acta-secondary" data-scm-open-iframe data-iframe-url="<?= $h(self::inventoryPublicUrl($id)) ?>" data-iframe-title="Inventario #<?= $h((string) $id) ?>">Ver</button>
                <button type="button" class="scm-acta-button scm-acta-secondary" data-inventory-edit="<?= $h((string) $id) ?>">Editar</button>
                <?php if ($canDelete): ?><button type="button" class="scm-acta-button scm-acta-danger" data-inventory-delete="<?= $h((string) $id) ?>">Eliminar</button><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
          <button type="button" class="scm-acta-button" data-inventory-new>Añadir inventario de otro inmueble del contrato</button>
          <span data-corrective-review-message aria-live="polite"></span>
        </section>
      <?php else: ?>
        <?= $this->renderInventoryForm($context, $mode === 'edit' ? $this->inventoryTarget($context, $inventoryId) : []) ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $context @param array<string,mixed> $row */
  private function renderInventoryForm(array $context, array $row): string
  {
    $editing = $row !== [];
    $principalId = $this->correctiveReviewDigits($context['contract']['id_inventario'] ?? '');
    $additional = !$editing && $principalId !== '' && $this->inventoryExists($principalId, $context['inventories']);
    $state = $this->inventoryState($editing ? $row : $this->inventoryDefaults($context, $additional));
    $config = $this->inventoryConfig();
    $reportId = (int) ($row['id_reporte_administrativo'] ?? 0);
    $withTransport = true;
    if ($reportId > 0) {
      $withTransport = (int) $this->db->getVar("SELECT COALESCE(`transporte`, 0) FROM `{$this->db->table('jet_cct_reportes_administrativos')}` WHERE `_ID` = ? LIMIT 1", [$reportId]) > 0;
    }
    $state['data']['con_transporte'] = $withTransport ? 'Si' : 'No';
    if (!$editing) {
      $state['data']['tiene_delegado'] = '';
    }
    $previous = [];
    if (!$editing) {
      foreach ($this->inventoryPreviousOptions($context) as $id => $label) {
        $previous[] = ['id' => $id, 'label' => $label];
      }
    }
    $payload = [
      'editing' => $editing,
      'additional' => $additional,
      'inventoryId' => (int) ($row['_ID'] ?? 0),
      'version' => (string) ($row['cct_modified'] ?? ''),
      'hasReport' => $reportId > 0,
      'state' => $state,
      'catalog' => $this->inventoryCatalog(),
      'previous' => $previous,
      'billing' => ['base' => InventoryBilling::fee($config), 'transporte' => InventoryBilling::transport($config)],
      'voice' => $this->canUseInventoryVoice(),
    ];
    $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<form data-inventory-form' . ($editing ? ' data-inventory-editing' : '') . ' autocomplete="off" novalidate>'
      . '<script type="application/json" data-inventory-config>' . $json . '</script>'
      . '<div data-inventory-root></div><span data-corrective-review-message aria-live="polite"></span></form>';
  }

  private function canUseInventoryVoice(): bool
  {
    return false;
  }
}
