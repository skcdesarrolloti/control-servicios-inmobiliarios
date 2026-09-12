<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;
use SCM\Modules\CollectionManagement\CollectionPortfolioService;

trait HandlesCollectionManagement
{
  public function ajax_handler_collection_portfolio_import(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestiones_cobro')) {
      $this->jsonFail('No tienes permiso para importar la cartera.');
    }
    try {
      $result = $this->get_collection_portfolio_service()->import((array) ($_FILES['file'] ?? []));
      $duplicate = !empty($result['duplicate']);
      $this->jsonOk($result + [
        'message' => $duplicate
          ? 'Este auxiliar ya había sido procesado. La cartera no se duplicó.'
          : 'Auxiliar procesado. La cartera y los pagos detectados quedaron actualizados.',
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_collection_portfolio_action(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestiones_cobro')) {
      $this->jsonFail('No tienes permiso para actualizar la cartera.');
    }
    $portfolioId = max(0, (int) ($_POST['portfolio_id'] ?? 0));
    $operation = sanitize_key((string) ($_POST['operation'] ?? ''));
    $note = trim(wp_strip_all_tags((string) ($_POST['note'] ?? '')));
    try {
      $service = $this->get_collection_portfolio_service();
      if ($operation === 'reset_imports') {
        $confirm = strtoupper(trim(sanitize_text_field(wp_unslash((string) ($_POST['confirm'] ?? '')))));
        if ($confirm !== 'RESET') {
          $this->jsonFail('Confirma el reinicio de pruebas de cartera.');
        }
        $counts = $service->resetImportedData();
        $this->jsonOk($counts + [
          'message' => 'Datos de prueba de la 1380 limpiados. No se tocaron contratos, gestiones históricas ni notificaciones.',
        ]);
      }
      if (strpos($operation, 'bulk_') === 0) {
        $this->jsonOk($this->handle_collection_portfolio_bulk_action($service, $operation, $_POST));
      }
      if ($portfolioId <= 0) {
        $this->jsonFail('Selecciona un registro de cartera válido.');
      }
      if ($operation === 'manual_balance') {
        $balance = trim(sanitize_text_field(wp_unslash((string) ($_POST['balance'] ?? ''))));
        if ($balance === '') {
          $this->jsonFail('Indica el saldo que quieres anexar.');
        }
        $item = $service->setManualBalance($portfolioId, $balance, $note);
        $this->jsonOk(['item' => $item, 'message' => 'Saldo manual anexado a la cartera.']);
      }
      if ($operation === 'send_due_date') {
        $adminService = $this->get_admin_notifications_service();
        $item = $service->item($portfolioId);
        $tenantId = (int) ($item['tenant_id'] ?? 0);
        $contractId = (int) ($item['contract_id'] ?? 0);
        if ($tenantId <= 0 || $contractId <= 0) {
          $this->jsonFail('Este registro no tiene arrendatario y contrato vinculados para notificar.');
        }
        $dueDay = $adminService->normalizeCollectionDueDateDay((string) ($_POST['due_day'] ?? date('j')));
        $rawChannels = $_POST['notify_channels'] ?? [];
        $notifyChannels = array_map(
          static fn($value): string => sanitize_key((string) $value),
          is_array($rawChannels) ? $rawChannels : [$rawChannels]
        );
        $notifyChannels = array_values(array_unique(array_filter($notifyChannels, static fn(string $channel): bool => in_array($channel, ['email', 'whatsapp'], true))));
        if ($notifyChannels === []) {
          $this->jsonFail('Selecciona al menos un canal para enviar la notificación.');
        }

        $observation = $adminService->collectionDueDateReminderObservation($dueDay, $notifyChannels);
        $payload = [
          'tipo_gestion_cobro' => 'Canon',
          'observacion' => $observation,
          'volver_llamar' => 'No',
          'siguiente_fecha' => '',
          'siguiente_hora' => '',
          'otro_horario_cobro' => '',
          'contract_ids' => [$contractId],
        ];
        $result = $adminService->registerCollectionManagement([$tenantId], $payload);
        $created = (int) ($result['created'] ?? 0);
        if ($created > 0) {
          $service->recordManagement($portfolioId, $created, $observation, 'notificacion_fecha_pago');
        }

        $notifyResult = ['queued' => 0, 'failed' => 0, 'invalid' => 0, 'filtered' => 0];
        $notifyError = '';
        if ($created > 0) {
          try {
            $notifyIds = array_map('intval', (array) ($result['recipient_ids'] ?? [$tenantId]));
            $notificationMeta = $this->collection_management_notification_meta((array) ($result['managements'] ?? []));
            foreach ($notifyIds as $notifyId) {
              if ($notifyId <= 0) {
                continue;
              }
              if (!isset($notificationMeta[$notifyId])) {
                $notificationMeta[$notifyId] = ['__notification_meta' => []];
              }
              $notificationMeta[$notifyId]['__notification_meta']['collection_due_date'] = [
                'day' => $dueDay,
                'ordinal' => $adminService->collectionDueDateOrdinal($dueDay),
              ];
            }
            if ($notifyChannels !== []) {
              $notifyResult = $this->merge_admin_notification_results($notifyResult, $adminService->enqueue(
                'arrendatarios_activos',
                $notifyIds,
                $notifyChannels,
                'Notificación de fecha de pago',
                $adminService->collectionDueDateReminderMessage($dueDay),
                'scm_arrendatario_fecha_pago_v1',
                'scm_email_arrendatario_fecha_pago_v1',
                $notificationMeta,
                AdministrativeNotificationsService::COLLECTION_SMS_MAX
              ));
            }
          } catch (\Throwable $notifyException) {
            $notifyError = $notifyException->getMessage();
          }
        }

        $queued = (int) ($notifyResult['queued'] ?? 0);
        $message = $created > 0
          ? 'Notificación de fecha de pago registrada.' . ($queued > 0 ? " {$queued} notificación(es) encolada(s)." : '') . ($notifyError !== '' ? ' No se pudo encolar todo: ' . $notifyError : '')
          : 'No se registró la gestión. Revisa que el contrato siga activo.';
        $this->jsonOk($result + ['message' => $message, 'notifications' => $notifyResult, 'notification_error' => $notifyError]);
      }
      if (in_array($operation, ['mark_normal', 'mark_prejuridico', 'mark_siniestro'], true)) {
        $stage = str_replace('mark_', '', $operation);
        $item = $service->updateStage($portfolioId, $stage, $note);
        $internalNotifyResult = ['queued' => 0, 'failed' => 0, 'invalid' => 0, 'filtered' => 0];
        $internalNotifyError = '';
        if ($stage === 'siniestro') {
          $internalIds = $this->internalNotificationRecipientsForAction('contrato_siniestro');
          if ($internalIds === []) {
            $internalIds = $this->internalNotificationRecipientsForAction('cobro_prejuridico');
          }
          if ($internalIds !== []) {
            try {
              $adminService = $this->get_admin_notifications_service();
              $internalNotifyResult = $adminService->enqueue(
                'funcionarios',
                $internalIds,
                ['email'],
                'Contrato marcado como siniestro',
                $this->collection_siniestro_internal_notification_message($item, $note),
                '',
                AdministrativeNotificationsService::DEFAULT_EMAIL_TEMPLATE,
                [],
                AdministrativeNotificationsService::SMS_MAX
              );
            } catch (\Throwable $internalException) {
              $internalNotifyError = $internalException->getMessage();
            }
          }
        }
        $internalQueued = (int) ($internalNotifyResult['queued'] ?? 0);
        $message = $stage === 'siniestro'
          ? 'Contrato marcado como siniestro y reporte guardado en el historial del inmueble.' . ($internalQueued > 0 ? " {$internalQueued} aviso(s) interno(s)." : '') . ($internalNotifyError !== '' ? ' No se pudo encolar todo: ' . $internalNotifyError : '')
          : 'Estado de cobranza actualizado.';
        $this->jsonOk(['item' => $item, 'message' => $message, 'internal_notifications' => $internalNotifyResult, 'internal_notification_error' => $internalNotifyError]);
      }
      if ($operation === 'send_prejuridico') {
        $result = $service->generateLetter($portfolioId, 'prejuridico', true);
        $emailQueued = (int) ($result['email_queued'] ?? ($result['queued'] ?? 0));
        $internalNotifyResult = ['queued' => 0, 'failed' => 0, 'invalid' => 0, 'filtered' => 0];
        $internalNotifyError = '';
        $internalIds = $this->internalNotificationRecipientsForAction('cobro_prejuridico');
        if ($internalIds !== []) {
          try {
            $adminService = $this->get_admin_notifications_service();
            $internalNotifyResult = $adminService->enqueue(
              'funcionarios',
              $internalIds,
              ['email'],
              'Cobro prejuridico registrado',
              $this->collection_prejuridico_internal_notification_message($result),
              '',
              AdministrativeNotificationsService::DEFAULT_EMAIL_TEMPLATE,
              $this->collection_management_notification_meta_for_recipients((array) ($result['managements'] ?? []), $internalIds),
              AdministrativeNotificationsService::SMS_MAX
            );
          } catch (\Throwable $internalException) {
            $internalNotifyError = $internalException->getMessage();
          }
        }
        $internalQueued = (int) ($internalNotifyResult['queued'] ?? 0);
        $this->jsonOk($result + [
          'message' => $emailQueued > 0
            ? 'Carta prejurídica generada, reporte del inmueble registrado y ' . $emailQueued . ' correo(s) encolado(s).' . ($internalQueued > 0 ? " {$internalQueued} aviso(s) interno(s)." : '') . ($internalNotifyError !== '' ? ' No se pudo encolar todo: ' . $internalNotifyError : '')
            : 'La carta prejurídica se generó y el reporte del inmueble quedó registrado, pero no se encontró un correo válido para encolarla.' . ($internalQueued > 0 ? " {$internalQueued} aviso(s) interno(s)." : '') . ($internalNotifyError !== '' ? ' No se pudo encolar todo: ' . $internalNotifyError : ''),
          'internal_notifications' => $internalNotifyResult,
          'internal_notification_error' => $internalNotifyError,
        ]);
      }
      if ($operation === 'send_siniestro') {
        $result = $service->sendSiniestroNotification($portfolioId);
        $emailQueued = (int) ($result['email_queued'] ?? ($result['queued'] ?? 0));
        $whatsappQueued = (int) ($result['whatsapp_queued'] ?? 0);
        $whatsappFailed = (int) ($result['whatsapp_failed'] ?? 0);
        $deliveryParts = [];
        if ($emailQueued > 0) {
          $deliveryParts[] = $emailQueued . ' correo(s)';
        }
        if ($whatsappQueued > 0) {
          $deliveryParts[] = $whatsappQueued . ' WhatsApp';
        }
        $message = $deliveryParts !== []
          ? 'Aviso previo de siniestro registrado en gestión e historial, y encolado por ' . implode(' + ', $deliveryParts) . '.'
          : 'Aviso previo de siniestro registrado en gestión e historial, pero no se encontró un correo o celular válido para encolar la notificación.';
        if ($whatsappFailed > 0) {
          $message .= ' No se pudieron encolar ' . $whatsappFailed . ' WhatsApp.';
        }
        $this->jsonOk($result + [
          'message' => $message,
        ]);
      }
      $this->jsonFail('Operación de cartera no válida.');
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  /**
   * @param array<string,mixed> $post
   * @return array<string,mixed>
   */
  private function handle_collection_portfolio_bulk_action(CollectionPortfolioService $service, string $operation, array $post): array
  {
    $items = !empty($post['bulk_all_filtered'])
      ? $this->collection_bulk_items_from_filters($service, $operation, $post)
      : $this->collection_bulk_items($post);
    if ($items === []) {
      throw new \RuntimeException('Selecciona al menos un contrato para ejecutar la acción en lote.');
    }

    return match ($operation) {
      'bulk_management' => $this->handle_collection_bulk_management($service, $items, $post),
      'bulk_due_date' => $this->handle_collection_bulk_due_date($service, $items, $post),
      'bulk_prejuridico' => $this->handle_collection_bulk_prejuridico($service, $items),
      'bulk_siniestro' => $this->handle_collection_bulk_siniestro($service, $items),
      'bulk_mark_siniestro' => $this->handle_collection_bulk_mark_siniestro($service, $items, $post),
      default => throw new \RuntimeException('Operación masiva de cartera no válida.'),
    };
  }

  /**
   * @param array<string,mixed> $post
   * @return array<int,array{portfolio_id:int,tenant_id:int,contract_id:int}>
   */
  private function collection_bulk_items(array $post): array
  {
    $portfolioIds = $this->collection_bulk_int_array($post['portfolio_ids'] ?? []);
    $tenantIds = $this->collection_bulk_int_array($post['tenant_ids'] ?? []);
    $contractIds = $this->collection_bulk_int_array($post['contract_ids'] ?? []);
    $max = max(count($portfolioIds), count($tenantIds), count($contractIds));
    $items = [];
    $seen = [];
    for ($index = 0; $index < $max; $index++) {
      $portfolioId = (int) ($portfolioIds[$index] ?? 0);
      $tenantId = (int) ($tenantIds[$index] ?? 0);
      $contractId = (int) ($contractIds[$index] ?? 0);
      if ($portfolioId <= 0 && ($tenantId <= 0 || $contractId <= 0)) {
        continue;
      }
      $key = $this->collection_bulk_item_key($portfolioId, $tenantId, $contractId);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $items[] = ['portfolio_id' => $portfolioId, 'tenant_id' => $tenantId, 'contract_id' => $contractId];
      if (count($items) >= 500) {
        break;
      }
    }
    return $items;
  }

  private function collection_bulk_item_key(int $portfolioId, int $tenantId, int $contractId): string
  {
    return $portfolioId > 0 ? 'p:' . $portfolioId : 't:' . $tenantId . ':c:' . $contractId;
  }

  /**
   * @param array<string,mixed> $post
   * @return array<int,array{portfolio_id:int,tenant_id:int,contract_id:int}>
   */
  private function collection_bulk_items_from_filters(CollectionPortfolioService $service, string $operation, array $post): array
  {
    $source = sanitize_key((string) ($post['bulk_source'] ?? 'principal'));
    $limit = 500;
    if ($source === 'contratos') {
      $result = $service->contractsDashboard([
        'status' => sanitize_key((string) ($post['scmgc_contratos_estado'] ?? '')),
        'stage' => sanitize_key((string) ($post['scmgc_contratos_etapa'] ?? '')),
        'search' => sanitize_text_field(wp_unslash((string) ($post['scmgc_contratos_buscar'] ?? ''))),
        'all' => true,
        'limit' => $limit,
      ]);
    } else {
      $result = $service->dashboard([
        'status' => sanitize_key((string) ($post['scmgc_estado'] ?? '')),
        'stage' => sanitize_key((string) ($post['scmgc_etapa'] ?? '')),
        'movement' => sanitize_key((string) ($post['scmgc_movimiento'] ?? '')),
        'search' => sanitize_text_field(wp_unslash((string) ($post['scmgc_buscar'] ?? ''))),
        'all' => true,
        'limit' => $limit,
      ]);
    }

    $total = (int) (($result['pagination']['total'] ?? 0));
    if ($total > $limit) {
      throw new \RuntimeException('El filtro contiene ' . $total . ' registros. Refina el filtro para procesar máximo ' . $limit . ' registros por acción masiva.');
    }

    $excluded = $this->collection_bulk_items([
      'portfolio_ids' => $post['excluded_portfolio_ids'] ?? [],
      'tenant_ids' => $post['excluded_tenant_ids'] ?? [],
      'contract_ids' => $post['excluded_contract_ids'] ?? [],
    ]);
    $excludedKeys = [];
    foreach ($excluded as $excludedItem) {
      $excludedKeys[$this->collection_bulk_item_key(
        (int) $excludedItem['portfolio_id'],
        (int) $excludedItem['tenant_id'],
        (int) $excludedItem['contract_id']
      )] = true;
    }

    $items = [];
    $seen = [];
    foreach ((array) ($result['rows'] ?? []) as $row) {
      if (!is_array($row) || !$this->collection_bulk_row_is_eligible($row, $operation)) {
        continue;
      }
      $portfolioId = (int) ($row['id'] ?? 0);
      $tenantId = (int) ($row['tenant_id'] ?? 0);
      $contractId = (int) ($row['contract_id'] ?? 0);
      if ($portfolioId <= 0 && ($tenantId <= 0 || $contractId <= 0)) {
        continue;
      }
      $key = $this->collection_bulk_item_key($portfolioId, $tenantId, $contractId);
      if (isset($excludedKeys[$key])) {
        continue;
      }
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $items[] = ['portfolio_id' => $portfolioId, 'tenant_id' => $tenantId, 'contract_id' => $contractId];
    }

    if ($items === []) {
      throw new \RuntimeException('No hay contratos elegibles en los resultados filtrados para esta acción.');
    }

    return $items;
  }

  /** @param array<string,mixed> $row */
  private function collection_bulk_row_is_eligible(array $row, string $operation): bool
  {
    $portfolioId = (int) ($row['id'] ?? 0);
    $tenantId = (int) ($row['tenant_id'] ?? 0);
    $contractId = (int) ($row['contract_id'] ?? 0);
    if ($operation === 'bulk_management') {
      return $tenantId > 0 && $contractId > 0;
    }
    if ($operation === 'bulk_due_date') {
      return $portfolioId > 0 && $tenantId > 0 && $contractId > 0;
    }
    return $portfolioId > 0
      && $tenantId > 0
      && $contractId > 0
      && (string) ($row['status'] ?? '') === 'deuda';
  }

  /**
   * @param mixed $raw
   * @return int[]
   */
  private function collection_bulk_int_array($raw): array
  {
    if (!is_array($raw)) {
      $raw = [$raw];
    }
    return array_map(static fn($value): int => max(0, (int) $value), array_values($raw));
  }

  /** @param string[] $allowed */
  private function collection_bulk_channels($raw, array $allowed): array
  {
    $values = is_array($raw) ? $raw : [$raw];
    $channels = array_map(static fn($value): string => sanitize_key((string) $value), $values);
    return array_values(array_unique(array_filter($channels, static fn(string $channel): bool => in_array($channel, $allowed, true))));
  }

  /**
   * @param array<int,array{portfolio_id:int,tenant_id:int,contract_id:int}> $items
   * @param array<string,mixed> $post
   * @return array<string,mixed>
   */
  private function handle_collection_bulk_management(CollectionPortfolioService $service, array $items, array $post): array
  {
    $adminService = $this->get_admin_notifications_service();
    $concept = sanitize_text_field(wp_unslash((string) ($post['tipo_gestion_cobro'] ?? 'Canon')));
    $observation = trim(wp_strip_all_tags((string) ($post['observacion'] ?? '')));
    if ($observation === '') {
      throw new \RuntimeException('Escribe la observación que quedará en todas las gestiones seleccionadas.');
    }
    $channels = $this->collection_bulk_channels($post['notify_channels'] ?? [], ['email', 'whatsapp', 'sms']);
    $payloadBase = [
      'tipo_gestion_cobro' => $concept !== '' ? $concept : 'Canon',
      'observacion' => $observation,
      'volver_llamar' => 'No',
      'siguiente_fecha' => '',
      'siguiente_hora' => '',
      'otro_horario_cobro' => '',
    ];

    $summary = $this->collection_bulk_summary(count($items));
    $allManagements = [];
    $allRecipientIds = [];
    foreach ($items as $item) {
      try {
        [$portfolioId, $tenantId, $contractId] = $this->collection_bulk_resolve_contract($service, $item);
        if ($tenantId <= 0 || $contractId <= 0) {
          throw new \RuntimeException('No tiene arrendatario y contrato vinculados.');
        }
        $payload = $payloadBase + ['contract_ids' => [$contractId]];
        $result = $adminService->registerCollectionManagement([$tenantId], $payload);
        $created = (int) ($result['created'] ?? 0);
        if ($created <= 0) {
          throw new \RuntimeException('No se registró la gestión. Revisa que el contrato siga activo.');
        }
        if ($portfolioId > 0) {
          $service->recordManagement($portfolioId, $created, $observation, 'gestion_cobro');
        }
        $summary['processed']++;
        $managements = (array) ($result['managements'] ?? []);
        $recipientIds = array_map('intval', (array) ($result['recipient_ids'] ?? [$tenantId]));
        $allManagements = array_merge($allManagements, $managements);
        $allRecipientIds = array_merge($allRecipientIds, $recipientIds);
        if ($channels !== []) {
          $notifyResult = $adminService->enqueue(
            'arrendatarios_activos',
            $recipientIds,
            $channels,
            'Gestión de cobro registrada',
            $adminService->collectionNotificationMessage($payload),
            'scm_arrendatario_gestion_cobro_v1',
            'scm_email_arrendatario_gestion_cobro_v1',
            $this->collection_management_notification_meta($managements),
            AdministrativeNotificationsService::COLLECTION_SMS_MAX
          );
          $summary['notifications'] = $this->merge_admin_notification_results((array) $summary['notifications'], $notifyResult);
        }
      } catch (\Throwable $exception) {
        $summary['failed']++;
        $this->collection_bulk_add_error($summary, $item, $exception->getMessage());
      }
    }

    $internalIds = $this->internalNotificationRecipientsForAction('gestion_cobro');
    if ($internalIds !== [] && $allManagements !== []) {
      try {
        $internalResult = $adminService->enqueue(
          'funcionarios',
          $internalIds,
          ['email'],
          'Gestiones de cobro registradas en lote',
          $this->collection_management_internal_notification_message(
            $allManagements,
            $payloadBase,
            $channels !== [] ? $adminService->collectionNotificationMessage($payloadBase) : '',
            (int) $summary['processed'],
            (int) $summary['failed']
          ),
          '',
          AdministrativeNotificationsService::DEFAULT_EMAIL_TEMPLATE,
          $this->collection_management_notification_meta_for_recipients($allManagements, $internalIds),
          AdministrativeNotificationsService::SMS_MAX
        );
        $summary['internal_notifications'] = $internalResult;
      } catch (\Throwable $exception) {
        $summary['internal_notification_error'] = $exception->getMessage();
      }
    }

    return $this->collection_bulk_finish($summary, 'Gestión masiva registrada');
  }

  /**
   * @param array<int,array{portfolio_id:int,tenant_id:int,contract_id:int}> $items
   * @param array<string,mixed> $post
   * @return array<string,mixed>
   */
  private function handle_collection_bulk_due_date(CollectionPortfolioService $service, array $items, array $post): array
  {
    $adminService = $this->get_admin_notifications_service();
    $dueDay = $adminService->normalizeCollectionDueDateDay((string) ($post['due_day'] ?? date('j')));
    $channels = $this->collection_bulk_channels($post['notify_channels'] ?? [], ['email', 'whatsapp']);
    if ($channels === []) {
      throw new \RuntimeException('Selecciona al menos WhatsApp o Email para notificar fecha de pago.');
    }
    $observation = $adminService->collectionDueDateReminderObservation($dueDay, $channels);
    $summary = $this->collection_bulk_summary(count($items));
    foreach ($items as $item) {
      try {
        [$portfolioId, $tenantId, $contractId] = $this->collection_bulk_resolve_contract($service, $item);
        if ($portfolioId <= 0 || $tenantId <= 0 || $contractId <= 0) {
          throw new \RuntimeException('Requiere foto de cartera, arrendatario y contrato vinculados.');
        }
        $payload = [
          'tipo_gestion_cobro' => 'Canon',
          'observacion' => $observation,
          'volver_llamar' => 'No',
          'siguiente_fecha' => '',
          'siguiente_hora' => '',
          'otro_horario_cobro' => '',
          'contract_ids' => [$contractId],
        ];
        $result = $adminService->registerCollectionManagement([$tenantId], $payload);
        $created = (int) ($result['created'] ?? 0);
        if ($created <= 0) {
          throw new \RuntimeException('No se registró la notificación. Revisa que el contrato siga activo.');
        }
        $service->recordManagement($portfolioId, $created, $observation, 'notificacion_fecha_pago');
        $recipientIds = array_map('intval', (array) ($result['recipient_ids'] ?? [$tenantId]));
        $notificationMeta = $this->collection_management_notification_meta((array) ($result['managements'] ?? []));
        foreach ($recipientIds as $recipientId) {
          if ($recipientId <= 0) {
            continue;
          }
          if (!isset($notificationMeta[$recipientId])) {
            $notificationMeta[$recipientId] = ['__notification_meta' => []];
          }
          $notificationMeta[$recipientId]['__notification_meta']['collection_due_date'] = [
            'day' => $dueDay,
            'ordinal' => $adminService->collectionDueDateOrdinal($dueDay),
          ];
        }
        $summary['notifications'] = $this->merge_admin_notification_results((array) $summary['notifications'], $adminService->enqueue(
          'arrendatarios_activos',
          $recipientIds,
          $channels,
          'Notificación de fecha de pago',
          $adminService->collectionDueDateReminderMessage($dueDay),
          'scm_arrendatario_fecha_pago_v1',
          'scm_email_arrendatario_fecha_pago_v1',
          $notificationMeta,
          AdministrativeNotificationsService::COLLECTION_SMS_MAX
        ));
        $summary['processed']++;
      } catch (\Throwable $exception) {
        $summary['failed']++;
        $this->collection_bulk_add_error($summary, $item, $exception->getMessage());
      }
    }
    return $this->collection_bulk_finish($summary, 'Notificaciones de fecha de pago procesadas');
  }

  /**
   * @param array<int,array{portfolio_id:int,tenant_id:int,contract_id:int}> $items
   * @return array<string,mixed>
   */
  private function handle_collection_bulk_prejuridico(CollectionPortfolioService $service, array $items): array
  {
    $adminService = $this->get_admin_notifications_service();
    $summary = $this->collection_bulk_summary(count($items));
    foreach ($items as $item) {
      try {
        $portfolioId = (int) $item['portfolio_id'];
        if ($portfolioId <= 0) {
          throw new \RuntimeException('Requiere foto de cartera vinculada.');
        }
        $result = $service->generateLetter($portfolioId, 'prejuridico', true);
        $summary['processed']++;
        $summary['email_queued'] += (int) ($result['email_queued'] ?? ($result['queued'] ?? 0));
        $internalIds = $this->internalNotificationRecipientsForAction('cobro_prejuridico');
        if ($internalIds !== []) {
          try {
            $summary['internal_notifications'] = $this->merge_admin_notification_results((array) $summary['internal_notifications'], $adminService->enqueue(
              'funcionarios',
              $internalIds,
              ['email'],
              'Cobro prejuridico registrado',
              $this->collection_prejuridico_internal_notification_message($result),
              '',
              AdministrativeNotificationsService::DEFAULT_EMAIL_TEMPLATE,
              $this->collection_management_notification_meta_for_recipients((array) ($result['managements'] ?? []), $internalIds),
              AdministrativeNotificationsService::SMS_MAX
            ));
          } catch (\Throwable $internalException) {
            $summary['internal_notification_error'] = $internalException->getMessage();
          }
        }
      } catch (\Throwable $exception) {
        $summary['failed']++;
        $this->collection_bulk_add_error($summary, $item, $exception->getMessage());
      }
    }
    return $this->collection_bulk_finish($summary, 'Prejurídicos procesados');
  }

  /**
   * @param array<int,array{portfolio_id:int,tenant_id:int,contract_id:int}> $items
   * @return array<string,mixed>
   */
  private function handle_collection_bulk_siniestro(CollectionPortfolioService $service, array $items): array
  {
    $summary = $this->collection_bulk_summary(count($items));
    foreach ($items as $item) {
      try {
        $portfolioId = (int) $item['portfolio_id'];
        if ($portfolioId <= 0) {
          throw new \RuntimeException('Requiere foto de cartera vinculada.');
        }
        $result = $service->sendSiniestroNotification($portfolioId);
        $summary['processed']++;
        $summary['email_queued'] += (int) ($result['email_queued'] ?? ($result['queued'] ?? 0));
        $summary['whatsapp_queued'] += (int) ($result['whatsapp_queued'] ?? 0);
        $summary['whatsapp_failed'] += (int) ($result['whatsapp_failed'] ?? 0);
      } catch (\Throwable $exception) {
        $summary['failed']++;
        $this->collection_bulk_add_error($summary, $item, $exception->getMessage());
      }
    }
    return $this->collection_bulk_finish($summary, 'Avisos previos de siniestro procesados');
  }

  /**
   * @param array<int,array{portfolio_id:int,tenant_id:int,contract_id:int}> $items
   * @param array<string,mixed> $post
   * @return array<string,mixed>
   */
  private function handle_collection_bulk_mark_siniestro(CollectionPortfolioService $service, array $items, array $post): array
  {
    $adminService = $this->get_admin_notifications_service();
    $note = trim(wp_strip_all_tags((string) ($post['note'] ?? 'Marcado en lote desde Gestiones de cobro.')));
    $summary = $this->collection_bulk_summary(count($items));
    foreach ($items as $itemRef) {
      try {
        $portfolioId = (int) $itemRef['portfolio_id'];
        if ($portfolioId <= 0) {
          throw new \RuntimeException('Requiere foto de cartera vinculada.');
        }
        $item = $service->updateStage($portfolioId, 'siniestro', $note);
        $summary['processed']++;
        $internalIds = $this->internalNotificationRecipientsForAction('contrato_siniestro');
        if ($internalIds === []) {
          $internalIds = $this->internalNotificationRecipientsForAction('cobro_prejuridico');
        }
        if ($internalIds !== []) {
          try {
            $summary['internal_notifications'] = $this->merge_admin_notification_results((array) $summary['internal_notifications'], $adminService->enqueue(
              'funcionarios',
              $internalIds,
              ['email'],
              'Contrato marcado como siniestro',
              $this->collection_siniestro_internal_notification_message($item, $note),
              '',
              AdministrativeNotificationsService::DEFAULT_EMAIL_TEMPLATE,
              [],
              AdministrativeNotificationsService::SMS_MAX
            ));
          } catch (\Throwable $internalException) {
            $summary['internal_notification_error'] = $internalException->getMessage();
          }
        }
      } catch (\Throwable $exception) {
        $summary['failed']++;
        $this->collection_bulk_add_error($summary, $itemRef, $exception->getMessage());
      }
    }
    return $this->collection_bulk_finish($summary, 'Contratos marcados como siniestro');
  }

  /**
   * @param array{portfolio_id:int,tenant_id:int,contract_id:int} $item
   * @return array{0:int,1:int,2:int}
   */
  private function collection_bulk_resolve_contract(CollectionPortfolioService $service, array $item): array
  {
    $portfolioId = (int) $item['portfolio_id'];
    $tenantId = (int) $item['tenant_id'];
    $contractId = (int) $item['contract_id'];
    if ($portfolioId > 0) {
      $portfolioItem = $service->item($portfolioId);
      $tenantId = (int) ($portfolioItem['tenant_id'] ?? $tenantId);
      $contractId = (int) ($portfolioItem['contract_id'] ?? $contractId);
    }
    return [$portfolioId, $tenantId, $contractId];
  }

  /** @return array<string,mixed> */
  private function collection_bulk_summary(int $requested): array
  {
    return [
      'requested' => $requested,
      'processed' => 0,
      'failed' => 0,
      'email_queued' => 0,
      'whatsapp_queued' => 0,
      'whatsapp_failed' => 0,
      'notifications' => ['queued' => 0, 'failed' => 0, 'invalid' => 0, 'filtered' => 0],
      'internal_notifications' => ['queued' => 0, 'failed' => 0, 'invalid' => 0, 'filtered' => 0],
      'internal_notification_error' => '',
      'errors' => [],
    ];
  }

  /** @param array<string,mixed> $summary @param array{portfolio_id:int,tenant_id:int,contract_id:int} $item */
  private function collection_bulk_add_error(array &$summary, array $item, string $message): void
  {
    if (count((array) $summary['errors']) >= 8) {
      return;
    }
    $context = $item['portfolio_id'] > 0 ? 'cartera #' . $item['portfolio_id'] : 'contrato #' . $item['contract_id'];
    $summary['errors'][] = $context . ': ' . $message;
  }

  /** @param array<string,mixed> $summary @return array<string,mixed> */
  private function collection_bulk_finish(array $summary, string $label): array
  {
    $message = $label . ': ' . (int) $summary['processed'] . ' procesado(s)';
    if ((int) $summary['failed'] > 0) {
      $message .= ' y ' . (int) $summary['failed'] . ' con novedad';
    }
    $externalNotifications = (array) ($summary['notifications'] ?? []);
    $internalNotifications = (array) ($summary['internal_notifications'] ?? []);
    $queued = (int) ($externalNotifications['queued'] ?? 0) + (int) ($summary['email_queued'] ?? 0) + (int) ($summary['whatsapp_queued'] ?? 0);
    $internalQueued = (int) ($internalNotifications['queued'] ?? 0);
    if ($queued > 0) {
      $message .= '. ' . $queued . ' notificación(es) externas en cola';
    }
    if ($internalQueued > 0) {
      $message .= '. ' . $internalQueued . ' aviso(s) interno(s)';
    }
    if ((string) ($summary['internal_notification_error'] ?? '') !== '') {
      $message .= '. No se pudo encolar todo internamente: ' . (string) $summary['internal_notification_error'];
    }
    if ((array) ($summary['errors'] ?? []) !== []) {
      $message .= '. Revisa novedades: ' . implode(' | ', (array) $summary['errors']);
    }
    $summary['message'] = $message . '.';
    return $summary;
  }

  public function ajax_handler_collection_portfolio_pdf(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestiones_cobro')) {
      $this->jsonFail('No tienes permiso para generar cartas de cartera.');
    }
    $portfolioId = max(0, (int) ($_POST['portfolio_id'] ?? 0));
    $letterType = sanitize_key((string) ($_POST['letter_type'] ?? ''));
    $preview = sanitize_key((string) ($_POST['mode'] ?? '')) === 'preview';
    try {
      $portfolioService = $this->get_collection_portfolio_service();
      $document = $preview
        ? $portfolioService->previewLetter($portfolioId, $letterType)
        : $portfolioService->generateLetter($portfolioId, $letterType, false);
      $path = (string) ($document['path'] ?? '');
      if ($path === '' || !is_file($path)) {
        throw new \RuntimeException('No se pudo preparar la carta en PDF.');
      }
      if (ob_get_level() > 0) {
        ob_end_clean();
      }
      header_remove('Content-Type');
      header('Content-Type: application/pdf');
      header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . addslashes((string) ($document['filename'] ?? 'carta-cartera.pdf')) . '"');
      header('Content-Length: ' . (string) filesize($path));
      header('Cache-Control: private, max-age=0, must-revalidate');
      readfile($path);
      if ($preview) {
        @unlink($path);
      }
      exit;
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_collection_portfolio_timeline(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestiones_cobro')) {
      $this->jsonFail('No tienes permiso para ver la trazabilidad de cartera.');
    }
    $portfolioId = max(0, (int) ($_POST['portfolio_id'] ?? 0));
    $contractId = max(0, (int) ($_POST['contract_id'] ?? 0));
    $propertyCode = sanitize_text_field(wp_unslash((string) ($_POST['property_code'] ?? '')));
    try {
      $this->jsonOk($this->get_collection_portfolio_service()->timeline($portfolioId, $contractId, $propertyCode));
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  private function get_collection_portfolio_service(): CollectionPortfolioService
  {
    return new CollectionPortfolioService($this->db);
  }

  /** @param array<string,mixed> $result */
  private function collection_prejuridico_internal_notification_message(array $result): string
  {
    $sender = trim(Auth::user());
    if ($sender === '') {
      $sender = 'Funcionario';
    }
    $managements = (array) ($result['managements'] ?? []);
    $summary = [];
    foreach (array_slice($managements, 0, 8) as $management) {
      if (!is_array($management)) {
        continue;
      }
      $contract = trim((string) ($management['contract_number'] ?? ''));
      $property = trim((string) ($management['property'] ?? ''));
      $piece = trim(($contract !== '' ? 'Contrato ' . $contract : '') . ($property !== '' ? ' · Inmueble ' . $property : ''));
      if ($piece !== '') {
        $summary[] = $piece;
      }
    }

    $lines = [
      'Se registró un cobro prejurídico desde el módulo Gestiones de cobro.',
      'Realizado por: ' . $sender . '.',
      'La carta prejurídica fue generada y el reporte del inmueble quedó registrado como Cobro prejuridico.',
    ];
    if ($summary !== []) {
      $lines[] = 'Registro: ' . implode('; ', $summary) . (count($managements) > count($summary) ? '; y más.' : '.');
    }

    $html = '';
    foreach ($lines as $line) {
      $html .= '<p style="margin:0 0 14px;">' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    $url = trim((string) ($result['url'] ?? ''));
    if ($url !== '') {
      $html .= '<div style="margin:20px 0 4px;text-align:center;">'
        . '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:inline-block;background:#f28c00;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:8px;">Ver carta prejur&iacute;dica en PDF</a>'
        . '</div>';
    }
    return $html;
  }

  /** @param array<string,mixed> $item */
  private function collection_siniestro_internal_notification_message(array $item, string $note = ''): string
  {
    $sender = trim(Auth::user());
    if ($sender === '') {
      $sender = 'Funcionario';
    }
    $tenant = trim((string) ($item['tenant_name'] ?? ''));
    $contract = trim((string) ($item['contract_number'] ?? ''));
    $property = trim((string) ($item['property_code'] ?? ''));
    $rawBalance = array_key_exists('balance', $item) ? $item['balance'] : null;
    $balance = $rawBalance !== null ? '$' . number_format((float) $rawBalance, 0, ',', '.') : 'Sin saldo registrado';
    $lines = [
      'Se marcó un contrato como siniestro desde el módulo Gestiones de cobro.',
      'Realizado por: ' . $sender . '.',
      'Arrendatario: ' . ($tenant !== '' ? $tenant : 'Sin nombre registrado') . '.',
      'Contrato: ' . ($contract !== '' ? $contract : '-') . ' · Inmueble: ' . ($property !== '' ? $property : '-') . '.',
      'Saldo actual: ' . $balance . '.',
      'El registro quedó guardado únicamente en el historial del inmueble con tipo Siniestro.',
    ];
    $note = trim($note);
    if ($note !== '') {
      $lines[] = 'Observación: ' . $note;
    }
    $html = '';
    foreach ($lines as $line) {
      $html .= '<p style="margin:0 0 14px;">' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    return $html;
  }
}
