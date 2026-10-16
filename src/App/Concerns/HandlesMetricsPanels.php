<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\Metrics\CaseMetricsReport;

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
    $month = trim((string) ($_POST['month'] ?? ''));
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
      $this->jsonFail('Selecciona un mes válido.');
    }
    try {
      $this->jsonOk(['metrics' => $this->maintenance_quote_month_metrics($month)]);
    } catch (\Throwable $exception) {
      error_log('[quote-metrics] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar las cotizaciones.');
    }
  }
}
