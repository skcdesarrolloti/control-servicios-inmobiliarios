<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\ContractOwnership\ContractOwnershipService;

trait HandlesContractOwnership
{
  public function ajax_handler_contract_owners(): void
  {
    $this->verifyCsrf();
    if ((!$this->canAccessDashboardTab('contratos_arrendamiento') && !$this->canAccessDashboardTab('funcionario_contratos'))) {
      $this->jsonFail('No tienes permiso para ver los contratos de arrendamiento.');
    }
    try {
      $snapshot = (new ContractOwnershipService($this->db))->snapshot();
      $snapshot['can_edit'] = $this->canUseDashboardAction('contract_owner_change');
      $this->jsonOk($snapshot);
    } catch (\Throwable $exception) {
      error_log('[contract-owners] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar los contratos.');
    }
  }

  public function ajax_handler_contract_owners_save(): void
  {
    $this->verifyCsrf();
    if ((!$this->canAccessDashboardTab('contratos_arrendamiento') && !$this->canAccessDashboardTab('funcionario_contratos')) || !$this->canUseDashboardAction('contract_owner_change')) {
      $this->jsonFail('No tienes permiso para cambiar el funcionario de los contratos.');
    }
    $decoded = json_decode(wp_unslash((string) ($_POST['changes'] ?? '[]')), true);
    $changes = [];
    foreach (is_array($decoded) ? $decoded : [] as $change) {
      if (!is_array($change)) {
        continue;
      }
      $clean = [
        'contract_id' => (int) ($change['contract_id'] ?? 0),
        'employee_id' => preg_replace('/[^0-9]/', '', (string) ($change['employee_id'] ?? '')) ?? '',
      ];
      if (array_key_exists('dias_gracia', $change) && is_scalar($change['dias_gracia']) && trim((string) $change['dias_gracia']) !== '') {
        $clean['dias_gracia'] = trim((string) $change['dias_gracia']);
      }
      $changes[] = $clean;
    }
    try {
      $result = (new ContractOwnershipService($this->db))->assign($changes);
      $this->jsonOk($result);
    } catch (\InvalidArgumentException $exception) {
      $this->jsonFail($exception->getMessage());
    } catch (\Throwable $exception) {
      error_log('[contract-owners] ' . $exception->getMessage());
      $this->jsonFail($exception instanceof \RuntimeException ? $exception->getMessage() : 'No se pudo cambiar el funcionario.');
    }
  }
}
