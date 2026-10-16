<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\ContractMovements\ContractMovementsReport;

trait HandlesContractMovements
{
  public function ajax_handler_contract_movements(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para ver métricas.');
    }
    try {
      $this->jsonOk((new ContractMovementsReport($this->db))->snapshot());
    } catch (\Throwable $exception) {
      error_log('[contract-movements] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar las ocupaciones y desocupaciones.');
    }
  }
}
