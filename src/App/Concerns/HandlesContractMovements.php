<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Modules\ContractMovements\ContractSimiAudit;

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

  public function ajax_handler_contract_simi_audit(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para auditar contratos.');
    }
    // Quien audita queda en el historial con su id_empleado real, no con el _ID de la sesión.
    $employeeId = $this->current_employee_id();
    if ($employeeId === '') {
      $this->jsonFail('Tu usuario no tiene un id de funcionario asociado.');
    }
    try {
      $result = (new ContractSimiAudit($this->db))->save(
        (int) ($_POST['contract_id'] ?? 0),
        is_string($_POST['estado_simi'] ?? null) ? $_POST['estado_simi'] : '',
        is_string($_POST['observaciones_estado_simi'] ?? null) ? $_POST['observaciones_estado_simi'] : '',
        ['id_empleado' => $employeeId, 'name' => Auth::user()],
        is_array($_POST['corrections'] ?? null) ? array_filter($_POST['corrections'], 'is_string') : []
      );
      $this->jsonOk($result + ['message' => $result['changed'] ? 'Auditoría guardada y registrada en el historial del inmueble y del contrato.' : 'No hubo cambios en la auditoría.']);
    } catch (\InvalidArgumentException | \DomainException $exception) {
      $this->jsonFail($exception->getMessage());
    } catch (\Throwable $exception) {
      error_log('[contract-simi-audit] ' . $exception->getMessage());
      $this->jsonFail('No se pudo guardar la auditoría del contrato.');
    }
  }
}
