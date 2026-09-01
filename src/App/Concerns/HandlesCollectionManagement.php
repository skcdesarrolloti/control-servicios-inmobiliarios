<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

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
        $notifyChannels = array_values(array_unique(array_filter($notifyChannels, static fn(string $channel): bool => in_array($channel, ['email', 'sms', 'whatsapp'], true))));
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
            $nonSmsChannels = array_values(array_filter($notifyChannels, static fn(string $channel): bool => $channel !== 'sms'));
            if ($nonSmsChannels !== []) {
              $notifyResult = $this->merge_admin_notification_results($notifyResult, $adminService->enqueue(
                'arrendatarios_activos',
                $notifyIds,
                $nonSmsChannels,
                'Notificación de fecha de pago',
                $adminService->collectionDueDateReminderMessage($dueDay),
                'scm_arrendatario_fecha_pago_v1',
                'scm_email_arrendatario_fecha_pago_v1',
                $notificationMeta,
                AdministrativeNotificationsService::COLLECTION_SMS_MAX
              ));
            }
            if (in_array('sms', $notifyChannels, true)) {
              $notifyResult = $this->merge_admin_notification_results($notifyResult, $adminService->enqueue(
                'arrendatarios_activos',
                $notifyIds,
                ['sms'],
                'Notificación de fecha de pago',
                $adminService->collectionDueDateReminderSmsMessage($dueDay),
                '',
                '',
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
        $this->jsonOk(['item' => $item, 'message' => 'Estado de cobranza actualizado.']);
      }
      if (in_array($operation, ['send_prejuridico', 'send_siniestro'], true)) {
        $type = str_replace('send_', '', $operation);
        $result = $service->generateLetter($portfolioId, $type, true);
        $queued = (int) ($result['queued'] ?? 0);
        $this->jsonOk($result + [
          'message' => $queued > 0
            ? 'Carta generada y ' . $queued . ' correo(s) encolado(s).'
            : 'La carta se generó, pero no se encontró un correo válido para encolarla.',
        ]);
      }
      $this->jsonFail('Operación de cartera no válida.');
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
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

  private function get_collection_portfolio_service(): CollectionPortfolioService
  {
    return new CollectionPortfolioService($this->db);
  }
}
