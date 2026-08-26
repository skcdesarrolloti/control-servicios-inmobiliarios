<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\CanonInsuranceAudit\CanonInsuranceAuditService;
use SCM\Modules\CanonInsuranceAudit\CanonInsuranceAuditView;

trait HandlesCanonInsuranceAudits
{
  private function canonInsuranceAuditService(): CanonInsuranceAuditService
  {
    return new CanonInsuranceAuditService($this->db);
  }

  /** @param array<string,mixed> $payload */
  private function renderCanonInsuranceAuditContent(array $payload): string
  {
    $payload['can_purge'] = $this->canManageCanonInsuranceAuditCleanup();
    return (new CanonInsuranceAuditView())->renderContent($payload);
  }

  public function ajax_handler_canon_insurance_audit_list(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para ver esta auditoria.');
    }
    try {
      $payload = $this->canonInsuranceAuditService()->dashboard([
        'audit_id' => (string) ($_POST['audit_id'] ?? ''),
        'period' => trim(sanitize_text_field(wp_unslash((string) ($_POST['period'] ?? '')))),
        'status' => sanitize_key((string) ($_POST['status'] ?? '')),
        'search' => trim(sanitize_text_field(wp_unslash((string) ($_POST['search'] ?? '')))),
      ]);
      $this->jsonOk(['html' => $this->renderCanonInsuranceAuditContent($payload)]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_import(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para realizar esta auditoria.');
    }
    $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
    $period = trim(sanitize_text_field(wp_unslash((string) ($_POST['period'] ?? ''))));
    try {
      $result = $this->canonInsuranceAuditService()->import($file, $period);
      $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
      $message = !empty($result['duplicate'])
        ? 'Este mismo archivo ya estaba registrado para el periodo. Se muestra la auditoria existente.'
        : sprintf(
          'Conciliacion mensual actualizada: %d contratos, %d correctos y %d con diferencias.',
          (int) ($summary['total'] ?? 0),
          (int) ($summary['compliant'] ?? 0),
          (int) ($summary['differences'] ?? 0)
        );
      $this->jsonOk([
        'html' => $this->renderCanonInsuranceAuditContent($result),
        'message' => $message,
        'duplicate' => !empty($result['duplicate']),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_observation(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para registrar observaciones.');
    }
    try {
      $result = $this->canonInsuranceAuditService()->saveObservation(
        (int) ($_POST['item_id'] ?? 0),
        trim(sanitize_textarea_field(wp_unslash((string) ($_POST['observation'] ?? ''))))
      );
      $this->jsonOk([
        'html' => $this->renderCanonInsuranceAuditContent($result),
        'message' => 'Observacion guardada en el registro de auditoria.',
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_report(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para enviar este informe.');
    }
    try {
      $result = $this->canonInsuranceAuditService()->sendReport(
        trim(sanitize_text_field(wp_unslash((string) ($_POST['period'] ?? '')))),
        trim(sanitize_textarea_field(wp_unslash((string) ($_POST['recipients'] ?? ''))))
      );
      $this->jsonOk([
        'html' => $this->renderCanonInsuranceAuditContent($result),
        'message' => sprintf('Informe encolado para %d destinatario(s).', (int) ($result['queued'] ?? 0)),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_purge(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para ver esta auditoria.');
    }
    if (!$this->canManageCanonInsuranceAuditCleanup()) {
      $this->jsonFail('Solo Gerencia Administrativa, Gerencia General o Desarrollo TI pueden borrar auditorias.');
    }
    $period = trim(sanitize_text_field(wp_unslash((string) ($_POST['period'] ?? ''))));
    $confirmation = trim(sanitize_text_field(wp_unslash((string) ($_POST['confirmation'] ?? ''))));
    if ($period === '' || $confirmation !== 'BORRAR ' . $period) {
      $this->jsonFail('Para borrar, escribe exactamente: BORRAR ' . $period);
    }
    try {
      $result = $this->canonInsuranceAuditService()->purgePeriod($period);
      $this->jsonOk([
        'html' => $this->renderCanonInsuranceAuditContent($result),
        'message' => sprintf(
          'Se borraron %d auditoria(s), %d fila(s) y %d informe(s) del periodo %s.',
          (int) ($result['deleted_audits'] ?? 0),
          (int) ($result['deleted_items'] ?? 0),
          (int) ($result['deleted_reports'] ?? 0),
          $period
        ),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_mandates(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para vincular mandatos.');
    }
    try {
      $payload = $this->canonInsuranceAuditService()->mandateLinkingRows(
        trim(sanitize_text_field(wp_unslash((string) ($_POST['search'] ?? '')))),
        (int) ($_POST['contract_id'] ?? 0)
      );
      $this->jsonOk([
        'html' => (new CanonInsuranceAuditView())->renderMandateLinking($payload),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_link_mandate(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para vincular mandatos.');
    }
    try {
      $service = $this->canonInsuranceAuditService();
      $payload = $service->linkMandateToContract(
        (int) ($_POST['contract_id'] ?? 0),
        (int) ($_POST['mandate_id'] ?? 0),
        trim(sanitize_text_field(wp_unslash((string) ($_POST['search'] ?? '')))),
        (int) ($_POST['context_contract_id'] ?? 0)
      );
      $dashboardPayload = $service->dashboard([
        'period' => trim(sanitize_text_field(wp_unslash((string) ($_POST['active_period'] ?? '')))),
        'status' => sanitize_key((string) ($_POST['active_status'] ?? '')),
        'search' => trim(sanitize_text_field(wp_unslash((string) ($_POST['active_search'] ?? '')))),
      ]);
      $view = new CanonInsuranceAuditView();
      $this->jsonOk([
        'html' => $view->renderMandateLinking($payload),
        'dashboard_html' => $this->renderCanonInsuranceAuditContent($dashboardPayload),
        'message' => (string) ($payload['message'] ?? 'Mandato vinculado.'),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_requests(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para completar solicitudes.');
    }
    try {
      $payload = $this->canonInsuranceAuditService()->requestNumberRows(
        trim(sanitize_text_field(wp_unslash((string) ($_POST['search'] ?? ''))))
      );
      $this->jsonOk([
        'html' => (new CanonInsuranceAuditView())->renderRequestNumbers($payload),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_canon_insurance_audit_update_request(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('auditoria_canon_aseguradoras')) {
      $this->jsonFail('No tienes permiso para completar solicitudes.');
    }
    try {
      $payload = $this->canonInsuranceAuditService()->updateContractRequestNumber(
        (int) ($_POST['contract_id'] ?? 0),
        trim(sanitize_text_field(wp_unslash((string) ($_POST['request_number'] ?? '')))),
        trim(sanitize_text_field(wp_unslash((string) ($_POST['search'] ?? ''))))
      );
      $this->jsonOk([
        'html' => (new CanonInsuranceAuditView())->renderRequestNumbers($payload),
        'message' => (string) ($payload['message'] ?? 'Solicitud guardada.'),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }
}
