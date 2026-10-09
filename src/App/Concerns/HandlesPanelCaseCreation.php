<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\PublicTickets\PublicTicketsService;

trait HandlesPanelCaseCreation
{
  private function panelCaseService(): PublicTicketsService
  {
    $this->verifyCsrf();
    if (!Auth::isLoggedIn() || !$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para crear casos desde este panel.');
    }
    return new PublicTicketsService($this->db, ['app_secret' => SCM_APP_SECRET]);
  }

  public function ajax_handler_panel_case_options(): void
  {
    $this->jsonOk($this->panelCaseService()->panelCaseOptions());
  }

  public function ajax_handler_panel_case_read(): void
  {
    $this->verifyCsrf();
    try {
      $this->jsonOk(['case' => $this->panelCaseReadPayload((int) ($_POST['case_id'] ?? 0))]);
    } catch (\InvalidArgumentException $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  private function panelCaseReadPayload(int $id): array
  {
    if (!Auth::isLoggedIn()) throw new \InvalidArgumentException('Inicia sesión para consultar el caso.');
    $ticket = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ?', [$id]);
    $copies = array_column(\SCM\Support\InternalNotificationRecipients::contactsForAction($this->db, 'nuevo_caso_panel'), 'employee_id');
    $isCopy = ($ticket['medio'] ?? '') === 'Panel administrativo' && in_array((string) Auth::employeeId(), $copies, true);
    if (!$ticket || (!$this->canAccessDashboardTab('metricas') && (string) ($ticket['id_empleado'] ?? '') !== (string) Auth::employeeId() && !$isCopy)) {
      throw new \InvalidArgumentException('No tienes permiso para consultar este caso.');
    }
    $case = $this->adminDueNativeTicketCasePayload($id, $this->adminDueStatusBucket($ticket));
    if ($case === []) throw new \InvalidArgumentException('No se pudo cargar el detalle del caso.');
    return $case;
  }

  public function ajax_handler_panel_case_search(): void
  {
    $service = $this->panelCaseService();
    try {
      $this->jsonOk(['contracts' => $service->searchPanelContracts(trim((string) ($_POST['query'] ?? '')), (string) ($_POST['by'] ?? ''))]);
    } catch (\InvalidArgumentException $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  public function ajax_handler_panel_case_create(): void
  {
    $service = $this->panelCaseService();
    // Persist a receipt under a lock so double clicks and network retries return the same case.
    $requestId = (string) ($_POST['request_id'] ?? '');
    if (!preg_match('/^[a-f0-9-]{36}$/D', $requestId)) $this->jsonFail('Identificador de solicitud inválido. Abre nuevamente el formulario.');
    $directory = SCM_STORAGE_PATH . '/data/panel-case-receipts';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) $this->jsonFail('No se pudo iniciar la creación del caso.');
    $receipt = fopen($directory . '/' . hash('sha256', Auth::employeeId() . ':' . $requestId) . '.json', 'c+');
    if (!$receipt || !flock($receipt, LOCK_EX)) $this->jsonFail('No se pudo bloquear la solicitud. Intenta nuevamente.');
    try {
      $saved = json_decode((string) stream_get_contents($receipt), true);
      if (is_array($saved) && !empty($saved['ticket_id'])) {
        $result = $saved;
      } else {
        $result = $service->createPanelTicket($_POST);
        rewind($receipt);
        ftruncate($receipt, 0);
        fwrite($receipt, (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        fflush($receipt);
      }
    } catch (\PDOException $e) {
      error_log('[panel_case_create] ' . $e->getMessage());
      $error = 'No se pudo guardar el caso. Intenta nuevamente.';
    } catch (\InvalidArgumentException | \RuntimeException $e) {
      $error = $e->getMessage();
    } finally {
      flock($receipt, LOCK_UN);
      fclose($receipt);
    }
    if (isset($error)) $this->jsonFail($error);
    $this->clearDashboardPerformanceCache('dashboard-metrics-v2');
    if (function_exists('apcu_delete')) apcu_delete('scm_dashboard_metrics_v2_' . md5((string) SCM_ROOT));
    $this->jsonOk($result);
  }
}
