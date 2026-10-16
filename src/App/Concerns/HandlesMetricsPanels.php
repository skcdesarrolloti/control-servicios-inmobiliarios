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
      $snapshot = (new QuoteMetricsReport($this->db))->snapshot();
      $snapshot['can_edit_response'] = $this->canUseDashboardAction('quote_respond');
      $this->jsonOk($snapshot);
    } catch (\Throwable $exception) {
      error_log('[quote-metrics] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron cargar las cotizaciones.');
    }
  }

  public function ajax_handler_quote_response_date(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas') || !$this->canUseDashboardAction('quote_respond')) {
      $this->jsonFail('No tienes permiso para completar fechas de respuesta.');
    }
    try {
      $result = (new QuoteMetricsReport($this->db))->setMissingResponseDate(
        (int) ($_POST['cotizacion_id'] ?? 0),
        trim((string) ($_POST['fecha_respuesta'] ?? '')),
        $this->current_employee_id()
      );
      $this->jsonOk($result);
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->jsonFail($exception->getMessage());
    } catch (\Throwable $exception) {
      error_log('[quote-response-date] ' . $exception->getMessage());
      $this->jsonFail('No se pudo guardar la fecha de respuesta.');
    }
  }
}
