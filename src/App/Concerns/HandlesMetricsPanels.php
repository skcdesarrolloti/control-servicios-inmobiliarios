<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\Metrics\CaseMetricsReport;
use SCM\Modules\Metrics\QuoteMetricsReport;

trait HandlesMetricsPanels
{
  public function ajax_handler_case_metrics(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para ver métricas.');
    }
    try {
      $this->jsonOk((new CaseMetricsReport($this->db))->snapshot());
    } catch (\Throwable $exception) {
      error_log('[case-metrics] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar las métricas de casos.');
    }
  }

  public function ajax_handler_quote_metrics(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para ver métricas.');
    }
    try {
      $this->jsonOk((new QuoteMetricsReport($this->db))->snapshot());
    } catch (\Throwable $exception) {
      error_log('[quote-metrics] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar las cotizaciones.');
    }
  }
}
