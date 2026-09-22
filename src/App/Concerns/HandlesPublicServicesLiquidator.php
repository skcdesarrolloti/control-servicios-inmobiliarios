<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\PublicServicesLiquidator\PublicServicesLiquidatorService;
use SCM\Modules\PublicServicesLiquidator\PublicServicesLiquidatorView;

trait HandlesPublicServicesLiquidator
{
  private function publicServicesLiquidatorService(): PublicServicesLiquidatorService
  {
    return new PublicServicesLiquidatorService($this->db);
  }

  private function renderPublicServicesLiquidatorPanel(): string
  {
    return (new PublicServicesLiquidatorView())->renderPanel();
  }

  public function ajax_handler_public_services_liquidator_search(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('liquidador_servicios_publicos')) {
      $this->jsonFail('No tienes permiso para usar el liquidador de servicios publicos.');
    }
    try {
      $rows = $this->publicServicesLiquidatorService()->searchContracts([
        'query' => trim(sanitize_text_field(wp_unslash((string) ($_POST['query'] ?? '')))),
      ]);
      $this->jsonOk([
        'html' => (new PublicServicesLiquidatorView())->renderContractsTable($rows),
        'count' => count($rows),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_public_services_liquidator_calculate(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('liquidador_servicios_publicos')) {
      $this->jsonFail('No tienes permiso para usar el liquidador de servicios publicos.');
    }
    try {
      $payload = $this->publicServicesLiquidatorService()->calculate($_POST);
      $this->jsonOk($payload + [
        'summary_html' => (new PublicServicesLiquidatorView())->renderSummary($payload),
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_public_services_liquidator_generate(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('liquidador_servicios_publicos')) {
      $this->jsonFail('No tienes permiso para usar el liquidador de servicios publicos.');
    }
    try {
      $payload = $this->publicServicesLiquidatorService()->generate($_POST);
      $this->jsonOk($payload + [
        'summary_html' => (new PublicServicesLiquidatorView())->renderSummary($payload),
        'message' => 'Ordenes de reembolso generadas.',
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }
}
