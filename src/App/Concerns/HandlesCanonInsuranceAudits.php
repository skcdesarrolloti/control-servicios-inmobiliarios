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
      $this->jsonOk(['html' => (new CanonInsuranceAuditView())->renderContent($payload)]);
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
        'html' => (new CanonInsuranceAuditView())->renderContent($result),
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
        'html' => (new CanonInsuranceAuditView())->renderContent($result),
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
        trim(sanitize_text_field(wp_unslash((string) ($_POST['period'] ?? ''))))
      );
      $this->jsonOk([
        'html' => (new CanonInsuranceAuditView())->renderContent($result),
        'message' => sprintf('Informe encolado para %d destinatario(s) del cargo Coordinador Contractual.', (int) ($result['queued'] ?? 0)),
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
        trim(sanitize_text_field(wp_unslash((string) ($_POST['search'] ?? ''))))
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
      $payload = $this->canonInsuranceAuditService()->linkMandateToContract(
        (int) ($_POST['contract_id'] ?? 0),
        (int) ($_POST['mandate_id'] ?? 0),
        trim(sanitize_text_field(wp_unslash((string) ($_POST['search'] ?? ''))))
      );
      $this->jsonOk([
        'html' => (new CanonInsuranceAuditView())->renderMandateLinking($payload),
        'message' => (string) ($payload['message'] ?? 'Mandato vinculado.'),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }
}
