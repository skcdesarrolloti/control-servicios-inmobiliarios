<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\RentIncrease\RentIncreaseService;
use SCM\Modules\RentIncrease\RentIncreaseView;

trait HandlesRentIncreaseLetters
{
  private function rentIncreaseService(): RentIncreaseService
  {
    return new RentIncreaseService($this->db);
  }

  /** @param array<string,mixed> $internalNotificationConfig */
  private function renderRentIncreaseLettersPanel(array $internalNotificationConfig = []): string
  {
    return (new RentIncreaseView())->renderPanel($internalNotificationConfig);
  }

  /** @return array{can_manage:bool,settings:array<string,array<int,string>>,funcionarios:array<int,array<string,string>>} */
  private function rentIncreaseInternalNotificationConfig(bool $canManage): array
  {
    return [
      'can_manage' => $canManage,
      'settings' => $canManage ? $this->internalNotificationSettingsConfig() : [],
      'funcionarios' => $canManage ? $this->internalNotificationFuncionarioOptions() : [],
    ];
  }

  public function ajax_handler_rent_increase_letters_list(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cartas_aumento')) {
      $this->jsonFail('No tienes permiso para ver cartas de aumento.');
    }
    $scope = sanitize_key((string) ($_POST['scope'] ?? 'contracts'));
    if (!in_array($scope, ['contracts', 'canon', 'administracion'], true)) {
      $scope = 'contracts';
    }
    $filters = [
      'page' => max(1, (int) ($_POST['page'] ?? 1)),
      'per_page' => max(10, min(100, (int) ($_POST['per_page'] ?? 30))),
      'canon_from' => trim(sanitize_text_field(wp_unslash((string) ($_POST['canon_from'] ?? '')))),
      'canon_to' => trim(sanitize_text_field(wp_unslash((string) ($_POST['canon_to'] ?? '')))),
      'admin_from' => trim(sanitize_text_field(wp_unslash((string) ($_POST['admin_from'] ?? '')))),
      'admin_to' => trim(sanitize_text_field(wp_unslash((string) ($_POST['admin_to'] ?? '')))),
      'month' => max(0, min(12, (int) ($_POST['month'] ?? 0))),
      'propietario' => trim(sanitize_text_field(wp_unslash((string) ($_POST['propietario'] ?? '')))),
      'arrendatario' => trim(sanitize_text_field(wp_unslash((string) ($_POST['arrendatario'] ?? '')))),
      'contrato' => trim(sanitize_text_field(wp_unslash((string) ($_POST['contrato'] ?? '')))),
      'inmueble' => trim(sanitize_text_field(wp_unslash((string) ($_POST['inmueble'] ?? '')))),
    ];
    try {
      $service = $this->rentIncreaseService();
      $payload = $scope === 'contracts' ? $service->activeContracts($filters) : $service->letters($filters, $scope);
      $view = new RentIncreaseView();
      $this->jsonOk([
        'scope' => $scope,
        'table_html' => $view->renderTable((array) ($payload['rows'] ?? []), $scope),
        'pagination_html' => $view->renderPagination($payload),
        'count' => (string) ((int) ($payload['total'] ?? 0)),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_rent_increase_letters_create(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('cartas_aumento')) {
      $this->jsonFail('No tienes permiso para crear cartas de aumento.');
    }
    $input = [];
    foreach ([
      'type',
      'contract_id',
      'fecha',
      'ciudad',
      'incremento',
      'canon',
      'administracion',
      'vigencia_aumento',
      'tiene_retroactivos',
      'retroactivo_administracion',
      'mes_inicio',
      'mes_final',
    ] as $field) {
      $input[$field] = trim(sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? ''))));
    }
    try {
      $result = $this->rentIncreaseService()->createLetter($input);
      $queued = (array) ($result['queued'] ?? []);
      $this->jsonOk([
        'message' => sprintf(
          'Carta creada. Correos: %d, WhatsApp: %d, internos: %d.',
          (int) ($queued['email'] ?? 0),
          (int) ($queued['whatsapp'] ?? 0),
          (int) ($queued['internal'] ?? 0)
        ),
        'letter_id' => (int) ($result['letter_id'] ?? 0),
        'letter_url' => (string) ($result['letter_url'] ?? ''),
        'queued' => $queued,
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }
}
