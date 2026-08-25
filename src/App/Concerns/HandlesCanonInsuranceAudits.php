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
      $selected = is_array($result['selected_audit'] ?? null) ? $result['selected_audit'] : [];
      $message = !empty($result['duplicate'])
        ? 'Este mismo archivo ya estaba registrado para el periodo. Se muestra la auditoria existente.'
        : sprintf(
          'Auditoria registrada: %d filas, %d coincidencias y %d diferencias.',
          (int) ($selected['total_rows'] ?? 0),
          (int) ($selected['compliant_rows'] ?? 0),
          (int) ($selected['difference_rows'] ?? 0)
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
      $result = $this->canonInsuranceAuditService()->sendReport((int) ($_POST['audit_id'] ?? 0));
      $this->jsonOk([
        'html' => (new CanonInsuranceAuditView())->renderContent($result),
        'message' => sprintf('Informe encolado para %d destinatario(s) del cargo Coordinador Contractual.', (int) ($result['queued'] ?? 0)),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }
}
