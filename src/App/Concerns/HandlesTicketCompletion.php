<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Modules\TicketCompletion\CompletionView;

trait HandlesTicketCompletion
{
  public function canAccessTicketCompletion(int $ticketId): bool
  {
    if (!Auth::isLoggedIn() || $ticketId <= 0) {
      return false;
    }
    $ticket = (new CompletionRepository($this->db))->ticket($ticketId);
    if ($this->canAccessDashboardTab('abiertos')) {
      return true;
    }
    $employee = $this->db->getRow('SELECT id_empleado FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID = ?', [Auth::userId()]);
    return $this->canAccessDashboardTab('mis_tickets') && $employee
      && trim((string) $employee['id_empleado']) !== ''
      && trim((string) $ticket['id_empleado']) === trim((string) $employee['id_empleado']);
  }

  public function ajax_handler_ticket_completion(): void
  {
    $this->verifyCsrf();
    $ticketId = (int) ($_POST['ticket_pk'] ?? 0);
    try {
      if (!$this->canAccessTicketCompletion($ticketId)) {
        http_response_code(403);
        $this->jsonFail('No tienes permiso para gestionar el acta de este ticket.');
      }
      $repo = new CompletionRepository($this->db);
      $repo->requireSchema();
      $service = new CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
      $operation = (string) ($_POST['operation'] ?? 'read');
      $employee = $this->db->getRow('SELECT id_empleado FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID = ?', [Auth::userId()]);
      $actor = ['user_id' => Auth::userId(), 'employee_id' => (string) ($employee['id_empleado'] ?? Auth::userId()), 'name' => Auth::user()];
      $result = [];
      if ($operation === 'create') {
        $result = $service->create($ticketId, $_POST, $actor);
      } elseif (in_array($operation, ['resend', 'cancel'], true)) {
        $id = (int) ($_POST['act_id'] ?? 0);
        if ((int) $repo->act($id)['ticket_pk'] !== $ticketId) {
          throw new \DomainException('El acta no pertenece al ticket seleccionado.');
        }
        if ($operation === 'resend') {
          $result = $service->resend($id);
        } else {
          $service->cancel($id, (string) ($_POST['reason'] ?? ''), $actor);
          $result = ['message' => 'Acta anulada. Puedes generar una nueva versión; el ticket sigue abierto.'];
        }
      } elseif ($operation !== 'read') {
        throw new \DomainException('Operación de acta no válida.');
      }
      $this->jsonOk($result + ['html' => (new CompletionView())->panel($service->context($ticketId), $service)]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }
}
