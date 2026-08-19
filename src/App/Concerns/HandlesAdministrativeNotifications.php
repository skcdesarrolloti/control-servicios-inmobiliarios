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
    $contractStatus = $this->sanitize_admin_notification_contract_status((string) ($_POST['contract_status'] ?? ''));
    $inmuebleSimi = trim(sanitize_text_field(wp_unslash((string) ($_POST['inmueble_simi'] ?? ''))));
    $contractNumber = trim(sanitize_text_field(wp_unslash((string) ($_POST['contract_number'] ?? ''))));
    $page = max(1, (int) ($_POST['page'] ?? 1));

    try {
      $result = $this->get_admin_notifications_service()->search($type, $query, $page, 20, $contractStatus, $inmuebleSimi, $contractNumber);
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
      ? $service->idsForFilter($type, $query, 5000, $contractStatus, $inmuebleSimi, $contractNumber)
      : array_map('intval', is_array($rawIds) ? $rawIds : [$rawIds]);

    $subject = trim(sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? ''))));
    $message = trim(wp_kses_post(wp_unslash((string) ($_POST['message'] ?? ''))));
    $whatsappTemplate = sanitize_key((string) ($_POST['whatsapp_template'] ?? ''));
    $emailTemplate = sanitize_key((string) ($_POST['email_template'] ?? ''));

    try {
      $result = $service->enqueue($type, $ids, $channels, $subject, $message, $whatsappTemplate, $emailTemplate);
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

  private function sanitize_admin_notification_type(string $type): string
  {
    $type = sanitize_key($type);
    return in_array($type, ['propietarios', 'arrendatarios', 'copropiedades', 'proveedores', 'funcionarios'], true)
      ? $type
      : 'propietarios';
  }

  private function sanitize_admin_notification_contract_status(string $status): string
  {
    $status = sanitize_key($status);
    return in_array($status, ['activos', 'no_activos'], true) ? $status : '';
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
      $email = trim((string) ($row['correo'] ?? ''));
      $phone = trim((string) ($row['celular_normalizado'] ?? ($row['celular'] ?? '')));
      $rawPhone = trim((string) ($row['celular'] ?? ''));
      $contractLabel = trim((string) ($row['contrato_arrendamiento_estado'] ?? ''));
      $contractSummary = trim((string) ($row['contratos_arrendamiento_resumen'] ?? ''));
      $contractClass = match ($contractLabel) {
        'Activo' => 'is-active-contract',
        'No activo' => 'is-inactive-contract',
        'Sin contrato' => 'is-empty-contract',
        default => '',
      };

      $html .= '<label class="scm-admin-notif-recipient" data-admin-notif-recipient-row>';
      $html .= '<input type="checkbox" value="' . esc_attr((string) $id) . '" data-admin-notif-recipient>';
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
      $html .= '</span>';
      $html .= '<span class="scm-admin-notif-contact">';
      $html .= '<span class="' . ($email !== '' ? 'is-ready' : 'is-missing') . '">Email: ' . esc_html($email !== '' ? $email : 'Sin correo') . '</span>';
      $html .= '<span class="' . ($phone !== '' ? 'is-ready' : 'is-missing') . '">Celular: ' . esc_html($phone !== '' ? $phone : ($rawPhone !== '' ? $rawPhone : 'Sin numero')) . '</span>';
      $html .= '</span>';
      $html .= '</label>';
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
