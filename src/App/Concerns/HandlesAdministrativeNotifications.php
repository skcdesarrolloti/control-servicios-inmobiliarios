<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;

trait HandlesAdministrativeNotifications
{
  private function get_admin_notifications_service(): AdministrativeNotificationsService
  {
    return new AdministrativeNotificationsService($this->db);
  }

  public function ajax_handler_admin_notifications_recipients(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('notificaciones')) {
      $this->jsonFail('No tienes permiso para usar Notificaciones.');
    }

    $type = $this->sanitize_admin_notification_type((string) ($_POST['type'] ?? 'propietarios'));
    $query = trim(sanitize_text_field(wp_unslash((string) ($_POST['q'] ?? ''))));
    $fieldFilters = $this->admin_notification_field_filters($_POST);
    $contractStatus = $this->sanitize_admin_notification_contract_status((string) ($_POST['contract_status'] ?? ''));
    $inmuebleSimi = trim(sanitize_text_field(wp_unslash((string) ($_POST['inmueble_simi'] ?? ''))));
    $contractNumber = trim(sanitize_text_field(wp_unslash((string) ($_POST['contract_number'] ?? ''))));
    $page = max(1, (int) ($_POST['page'] ?? 1));

    try {
      $result = $this->get_admin_notifications_service()->search($type, $query, $page, 20, $contractStatus, $inmuebleSimi, $contractNumber, $fieldFilters);
      $this->jsonOk([
        'html' => $this->render_admin_notification_recipient_rows($result['rows']),
        'pagination' => $this->render_admin_notification_pagination($result),
        'total' => (int) ($result['total'] ?? 0),
        'page' => (int) ($result['page'] ?? 1),
        'pages' => (int) ($result['pages'] ?? 1),
        'type_label' => (string) ($result['type_label'] ?? ''),
      ]);
    } catch (\Throwable $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  public function ajax_handler_admin_notifications_send(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('notificaciones')) {
      $this->jsonFail('No tienes permiso para usar Notificaciones.');
    }

    $service = $this->get_admin_notifications_service();
    $type = $this->sanitize_admin_notification_type((string) ($_POST['type'] ?? 'propietarios'));
    $query = trim(sanitize_text_field(wp_unslash((string) ($_POST['q'] ?? ''))));
    $fieldFilters = $this->admin_notification_field_filters($_POST);
    $contractStatus = $this->sanitize_admin_notification_contract_status((string) ($_POST['contract_status'] ?? ''));
    $inmuebleSimi = trim(sanitize_text_field(wp_unslash((string) ($_POST['inmueble_simi'] ?? ''))));
    $contractNumber = trim(sanitize_text_field(wp_unslash((string) ($_POST['contract_number'] ?? ''))));
    $allFiltered = trim((string) ($_POST['all_filtered'] ?? '')) === '1';
    $rawChannels = $_POST['channels'] ?? [];
    $rawIds = $_POST['ids'] ?? [];

    $channels = array_map(
      static fn($value): string => sanitize_key((string) $value),
      is_array($rawChannels) ? $rawChannels : [$rawChannels]
    );
    $ids = $allFiltered
      ? $service->idsForFilter($type, $query, 5000, $contractStatus, $inmuebleSimi, $contractNumber, $fieldFilters)
      : array_map('intval', is_array($rawIds) ? $rawIds : [$rawIds]);

    $subject = trim(sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? ''))));
    $message = trim(wp_kses_post(wp_unslash((string) ($_POST['message'] ?? ''))));
    $whatsappTemplate = sanitize_key((string) ($_POST['whatsapp_template'] ?? ''));
    $emailTemplate = sanitize_key((string) ($_POST['email_template'] ?? ''));
    $importPayloadRaw = trim((string) wp_unslash($_POST['import_payload'] ?? ''));
    $importPayload = [];
    if ($importPayloadRaw !== '') {
      $decoded = json_decode($importPayloadRaw, true);
      if (is_array($decoded)) {
        $importPayload = $decoded;
      }
    }

    try {
      $result = $service->enqueue($type, $ids, $channels, $subject, $message, $whatsappTemplate, $emailTemplate, $importPayload);
      $queued = (int) ($result['queued'] ?? 0);
      $invalid = (int) ($result['invalid'] ?? 0);
      $failed = (int) ($result['failed'] ?? 0);
      $filtered = (int) ($result['filtered'] ?? 0);
      $messageText = $queued > 0
        ? sprintf('Se encolaron %d notificaciones.%s%s%s', $queued, $invalid > 0 ? " {$invalid} sin contacto valido." : '', $filtered > 0 ? " {$filtered} bloqueadas por preferencia." : '', $failed > 0 ? " {$failed} fallaron." : '')
        : 'No se pudo encolar ninguna notificacion. Revisa contactos y canales.';
      $this->jsonOk($result + ['message' => $messageText]);
    } catch (\Throwable $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  public function ajax_handler_admin_notifications_import(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('notificaciones')) {
      $this->jsonFail('No tienes permiso para usar Notificaciones.');
    }

    $type = $this->sanitize_admin_notification_type((string) ($_POST['type'] ?? 'propietarios_activos'));
    $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];

    try {
      $result = $this->get_admin_notifications_service()->importRecipientsFromFile($type, $file);
      $matched = (int) ($result['matched'] ?? 0);
      $unmatched = (int) ($result['unmatched'] ?? 0);
      $duplicates = (int) ($result['duplicates'] ?? 0);
      $usable = (int) ($result['usable_rows'] ?? 0);
      $message = $matched > 0
        ? sprintf('Excel importado: %d destinatarios encontrados de %d filas utiles.%s%s', $matched, $usable, $unmatched > 0 ? " {$unmatched} filas sin coincidencia." : '', $duplicates > 0 ? " {$duplicates} duplicados omitidos." : '')
        : 'No se encontraron destinatarios con ese Excel. Revisa columnas contrato o inmueble_simi.';
      $this->jsonOk([
        'html' => $this->render_admin_notification_recipient_rows((array) ($result['rows'] ?? [])),
        'payload' => (array) ($result['payload'] ?? []),
        'matched' => $matched,
        'unmatched' => $unmatched,
        'duplicates' => $duplicates,
        'usable_rows' => $usable,
        'type_label' => (string) ($result['type_label'] ?? ''),
        'message' => $message,
      ]);
    } catch (\Throwable $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  public function ajax_handler_admin_notifications_collection(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('notificaciones')) {
      $this->jsonFail('No tienes permiso para registrar gestiones de cobro.');
    }

    $service = $this->get_admin_notifications_service();
    $type = $this->sanitize_admin_notification_type((string) ($_POST['type'] ?? 'arrendatarios_activos'));
    if ($type !== 'arrendatarios_activos') {
      $this->jsonFail('La gestion de cobro solo aplica para Arrendatarios activos.');
    }

    $query = trim(sanitize_text_field(wp_unslash((string) ($_POST['q'] ?? ''))));
    $fieldFilters = $this->admin_notification_field_filters($_POST);
    $contractStatus = $this->sanitize_admin_notification_contract_status((string) ($_POST['contract_status'] ?? ''));
    $inmuebleSimi = trim(sanitize_text_field(wp_unslash((string) ($_POST['inmueble_simi'] ?? ''))));
    $contractNumber = trim(sanitize_text_field(wp_unslash((string) ($_POST['contract_number'] ?? ''))));
    $allFiltered = trim((string) ($_POST['all_filtered'] ?? '')) === '1';
    $rawIds = $_POST['ids'] ?? [];
    $ids = $allFiltered
      ? $service->idsForFilter($type, $query, 5000, $contractStatus, $inmuebleSimi, $contractNumber, $fieldFilters)
      : array_map('intval', is_array($rawIds) ? $rawIds : [$rawIds]);

    $payload = [
      'tipo_gestion_cobro' => sanitize_text_field(wp_unslash((string) ($_POST['tipo_gestion_cobro'] ?? 'Canon'))),
      'observacion' => trim(wp_kses_post(wp_unslash((string) ($_POST['observacion'] ?? '')))),
      'volver_llamar' => sanitize_text_field(wp_unslash((string) ($_POST['volver_llamar'] ?? 'No'))),
      'siguiente_fecha' => sanitize_text_field(wp_unslash((string) ($_POST['siguiente_fecha'] ?? ''))),
      'siguiente_hora' => sanitize_text_field(wp_unslash((string) ($_POST['siguiente_hora'] ?? ''))),
      'otro_horario_cobro' => trim(sanitize_text_field(wp_unslash((string) ($_POST['otro_horario_cobro'] ?? '')))),
    ];
    $rawChannels = $_POST['notify_channels'] ?? [];
    $notifyChannels = array_map(
      static fn($value): string => sanitize_key((string) $value),
      is_array($rawChannels) ? $rawChannels : [$rawChannels]
    );

    try {
      $result = $service->registerCollectionManagement($ids, $payload);
      $created = (int) ($result['created'] ?? 0);
      $skipped = (int) ($result['skipped'] ?? 0);
      $notifyResult = ['queued' => 0, 'failed' => 0, 'invalid' => 0, 'filtered' => 0];
      $notifyError = '';
      if ($created > 0 && $notifyChannels !== []) {
        try {
          $notifyIds = array_map('intval', (array) ($result['recipient_ids'] ?? $ids));
          $notifyResult = $service->enqueue(
            $type,
            $notifyIds,
            $notifyChannels,
            'Gestion de cobro de contrato de arrendamiento',
            $service->collectionNotificationMessage($payload),
            'scm_arrendatario_gestion_cobro_v1',
            'scm_email_arrendatario_gestion_cobro_v1'
          );
        } catch (\Throwable $notifyException) {
          $notifyError = $notifyException->getMessage();
        }
      }
      $queued = (int) ($notifyResult['queued'] ?? 0);
      $message = $created > 0
        ? sprintf('Se registraron %d gestiones de cobro.%s%s%s', $created, $skipped > 0 ? " {$skipped} contratos omitidos." : '', $queued > 0 ? " {$queued} notificaciones encoladas." : '', $notifyError !== '' ? ' No se pudo encolar notificacion: ' . $notifyError : '')
        : 'No se registro ninguna gestion de cobro. Revisa que los arrendatarios tengan contratos activos.';
      $this->jsonOk($result + [
        'message' => $message,
        'notifications' => $notifyResult,
        'notification_error' => $notifyError,
      ]);
    } catch (\Throwable $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  private function sanitize_admin_notification_type(string $type): string
  {
    $type = sanitize_key($type);
    return array_key_exists($type, $this->get_admin_notifications_service()->types())
      ? $type
      : 'propietarios_activos';
  }

  private function sanitize_admin_notification_contract_status(string $status): string
  {
    $status = sanitize_key($status);
    return in_array($status, ['activos', 'no_activos'], true) ? $status : '';
  }

  /**
   * @param array<string,mixed> $input
   * @return array{name:string,email:string,phone:string,document:string}
   */
  private function admin_notification_field_filters(array $input): array
  {
    return [
      'name' => trim(sanitize_text_field(wp_unslash((string) ($input['nombre'] ?? $input['name'] ?? '')))),
      'email' => trim(sanitize_text_field(wp_unslash((string) ($input['correo'] ?? $input['email'] ?? '')))),
      'phone' => trim(sanitize_text_field(wp_unslash((string) ($input['celular'] ?? $input['phone'] ?? '')))),
      'document' => trim(sanitize_text_field(wp_unslash((string) ($input['documento'] ?? $input['document'] ?? '')))),
    ];
  }

  /** @param array<int,array<string,mixed>> $rows */
  private function render_admin_notification_recipient_rows(array $rows): string
  {
    if ($rows === []) {
      return '<div class="scm-admin-notif-empty"><strong>No hay destinatarios.</strong><span>Prueba con otro tipo o cambia la busqueda.</span></div>';
    }

    $html = '';
    foreach ($rows as $row) {
      $id = (int) ($row['_ID'] ?? 0);
      if ($id <= 0) {
        continue;
      }
      $name = trim((string) ($row['nombre'] ?? 'Sin nombre'));
      $typeLabel = trim((string) ($row['tipo_label'] ?? 'Destinatario'));
      $actorType = trim((string) ($row['tipo_actor'] ?? ''));
      $email = trim((string) ($row['correo'] ?? ''));
      $phone = trim((string) ($row['celular_normalizado'] ?? ($row['celular'] ?? '')));
      $rawPhone = trim((string) ($row['celular'] ?? ''));
      $contractLabel = trim((string) ($row['contrato_arrendamiento_estado'] ?? ''));
      $contractSummary = trim((string) ($row['contratos_arrendamiento_resumen'] ?? ''));
      $importMeta = is_array($row['_scm_import_meta'] ?? null) ? $row['_scm_import_meta'] : [];
      $contractClass = match ($contractLabel) {
        'Activo' => 'is-active-contract',
        'No activo' => 'is-inactive-contract',
        'Sin contrato' => 'is-empty-contract',
        default => '',
      };

      $html .= '<div class="scm-admin-notif-recipient" data-admin-notif-recipient-row>';
      $html .= '<input type="checkbox" value="' . esc_attr((string) $id) . '" data-admin-notif-recipient aria-label="Seleccionar ' . esc_attr($name) . '">';
      $html .= '<span class="scm-admin-notif-avatar" aria-hidden="true">' . esc_html(mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8')) . '</span>';
      $html .= '<span class="scm-admin-notif-person">';
      $html .= '<strong>' . esc_html($name) . '</strong>';
      $html .= '<small>' . esc_html($typeLabel) . ' · ID ' . esc_html((string) $id) . '</small>';
      if ($contractLabel !== '') {
        $html .= '<span class="scm-admin-notif-contract-badge ' . esc_attr($contractClass) . '">' . esc_html($contractLabel) . '</span>';
      }
      if ($contractSummary !== '') {
        $html .= '<span class="scm-admin-notif-contract-summary">' . esc_html($contractSummary) . '</span>';
      }
      if ($importMeta !== []) {
        $importBits = [];
        foreach ([
          'contrato_excel' => 'Contrato',
          'inmueble_simi_excel' => 'Inmueble',
          'canon_excel' => 'Canon',
          'mes_excel' => 'Periodo',
        ] as $key => $label) {
          $value = trim((string) ($importMeta[$key] ?? ''));
          if ($value !== '' && $value !== 'Sin canon en Excel') {
            $importBits[] = $label . ': ' . $value;
          }
        }
        if ($importBits !== []) {
          $html .= '<span class="scm-admin-notif-import-summary">' . esc_html('Excel · ' . implode(' · ', $importBits)) . '</span>';
        }
      }
      $html .= '</span>';
      $html .= '<span class="scm-admin-notif-contact">';
      $html .= '<span class="' . ($email !== '' ? 'is-ready' : 'is-missing') . '">Email: ' . esc_html($email !== '' ? $email : 'Sin correo') . '</span>';
      $html .= '<span class="' . ($phone !== '' ? 'is-ready' : 'is-missing') . '">Celular: ' . esc_html($phone !== '' ? $phone : ($rawPhone !== '' ? $rawPhone : 'Sin numero')) . '</span>';
      $html .= '</span>';
      $html .= '<span class="scm-admin-notif-row-actions" aria-label="Acciones rapidas de contacto">';
      $html .= '<button type="button" class="scm-admin-notif-row-action scm-admin-notif-row-action--email" data-admin-notif-single-channel="email" data-admin-notif-single-id="' . esc_attr((string) $id) . '">Email</button>';
      $html .= '<button type="button" class="scm-admin-notif-row-action scm-admin-notif-row-action--sms" data-admin-notif-single-channel="sms" data-admin-notif-single-id="' . esc_attr((string) $id) . '">SMS</button>';
      $html .= '<button type="button" class="scm-admin-notif-row-action scm-admin-notif-row-action--whatsapp" data-admin-notif-single-channel="whatsapp" data-admin-notif-single-id="' . esc_attr((string) $id) . '">WhatsApp</button>';
      $html .= '<button type="button" class="scm-admin-notif-row-action scm-admin-notif-row-action--all" data-admin-notif-single-all-channels data-admin-notif-single-id="' . esc_attr((string) $id) . '">Todos los canales</button>';
      if ($actorType === 'arrendatarios_activos') {
        $html .= '<button type="button" class="scm-admin-notif-row-action scm-admin-notif-row-action--collection" data-admin-notif-single-collection data-admin-notif-single-id="' . esc_attr((string) $id) . '">Gestion de cobro</button>';
      }
      $html .= '</span>';
      $html .= '</div>';
    }

    return $html !== '' ? $html : '<div class="scm-admin-notif-empty"><strong>No hay destinatarios validos.</strong></div>';
  }

  /** @param array{page:int,pages:int,total:int,per_page:int} $result */
  private function render_admin_notification_pagination(array $result): string
  {
    $page = max(1, (int) ($result['page'] ?? 1));
    $pages = max(1, (int) ($result['pages'] ?? 1));
    $total = max(0, (int) ($result['total'] ?? 0));
    if ($pages <= 1) {
      return '<span class="scm-admin-notif-page-info">Total: ' . esc_html((string) $total) . '</span>';
    }

    $html = '<span class="scm-admin-notif-page-info">Pagina ' . esc_html((string) $page) . ' de ' . esc_html((string) $pages) . ' · Total: ' . esc_html((string) $total) . '</span>';
    $html .= '<div class="scm-admin-notif-page-actions">';
    $html .= '<button type="button" data-admin-notif-page="' . esc_attr((string) max(1, $page - 1)) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button>';
    $html .= '<button type="button" data-admin-notif-page="' . esc_attr((string) min($pages, $page + 1)) . '"' . ($page >= $pages ? ' disabled' : '') . '>Siguiente</button>';
    $html .= '</div>';
    return $html;
  }
}
