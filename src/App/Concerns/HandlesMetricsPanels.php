<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\App;
use SCM\Modules\Metrics\CaseMetricsReport;
use SCM\Modules\Metrics\QuoteLedgerReconciliation;
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
      $snapshot = (new QuoteMetricsReport($this->db, App::settings()))->snapshot();
      $snapshot['can_edit_response'] = $this->canUseDashboardAction('quote_respond');
      $snapshot['can_edit_quote'] = $this->canUseDashboardAction('quote_edit');
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
    $decoded = json_decode(wp_unslash((string) ($_POST['changes'] ?? '[]')), true);
    $changes = [];
    foreach (is_array($decoded) ? $decoded : [] as $change) {
      if (is_array($change)) {
        $changes[] = ['id' => (int) ($change['id'] ?? 0), 'date' => trim((string) ($change['date'] ?? ''))];
      }
    }
    try {
      $this->jsonOk((new QuoteMetricsReport($this->db, App::settings()))->setResponseDates(
        $changes,
        $this->current_employee_id(),
        (string) ($_POST['overwrite'] ?? '') === '1'
      ));
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->jsonFail($exception->getMessage());
    } catch (\Throwable $exception) {
      error_log('[quote-response-date] ' . $exception->getMessage());
      $this->jsonFail('No se pudieron guardar las fechas de respuesta.');
    }
  }

  /**
   * Conciliación por inmueble: corrige la fecha de respuesta, el valor total y/o la administración
   * de una cotización. Solo se envían los campos que cambiaron; cada uno exige su permiso.
   */
  public function ajax_handler_quote_ledger_update(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para ver métricas.');
    }
    $id = (int) ($_POST['cotizacion_id'] ?? 0);
    $text = static function (string $key): ?string {
      $value = trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
      return $value === '' ? null : $value;
    };
    $money = static function (string $key): ?int {
      $raw = preg_replace('/\D+/', '', (string) ($_POST[$key] ?? '')) ?? '';
      return $raw === '' ? null : (int) $raw;
    };
    $state = $text('estado');
    $created = $text('fecha_creacion');
    $response = $text('fecha_respuesta');
    $total = $money('total');
    $admin = $money('administracion');
    if ($state === null && $created === null && $response === null && $total === null && $admin === null) {
      $this->jsonFail('No hay cambios para guardar.');
    }
    if (($state !== null || $response !== null) && !$this->canUseDashboardAction('quote_respond')) {
      $this->jsonFail('No tienes permiso para cambiar el estado o la fecha de respuesta.');
    }
    if (($created !== null || $total !== null || $admin !== null) && !$this->canUseDashboardAction('quote_edit')) {
      $this->jsonFail('No tienes permiso para editar cotizaciones.');
    }
    $actor = $this->current_employee_id();
    $report = new QuoteMetricsReport($this->db, App::settings());
    try {
      $result = ['id' => $id];
      if ($state !== null || $created !== null || $response !== null) {
        $result = $report->updateStatusAndDates($id, $state, $created, $response, $actor) + $result;
      }
      if ($total !== null || $admin !== null) {
        $result += $report->updateValues($id, $total, $admin, $actor);
      }
      $this->jsonOk($result);
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->jsonFail($exception->getMessage());
    } catch (\Throwable $exception) {
      error_log('[quote-ledger-update] ' . $exception->getMessage());
      $this->jsonFail('No se pudo guardar la cotización.');
    }
  }

  public function ajax_handler_quote_assign_property(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas') || !$this->canUseDashboardAction('quote_edit')) {
      $this->jsonFail('No tienes permiso para editar cotizaciones.');
    }
    $decoded = json_decode(wp_unslash((string) ($_POST['ids'] ?? '[]')), true);
    try {
      $this->jsonOk((new QuoteMetricsReport($this->db, App::settings()))->assignCaseProperty(
        array_map('intval', is_array($decoded) ? $decoded : []),
        $this->current_employee_id()
      ));
    } catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->jsonFail($exception->getMessage());
    } catch (\Throwable $exception) {
      error_log('[quote-assign-property] ' . $exception->getMessage());
      $this->jsonFail('No se pudo asignar el inmueble.');
    }
  }

  public function ajax_handler_quote_ledger(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para ver métricas.');
    }
    $file = $_FILES['auxiliar'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
      $this->jsonFail('Sube el auxiliar contable (.xls o .xlsx).');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
      $this->jsonFail('El auxiliar supera 5 MB.');
    }
    try {
      $report = new QuoteMetricsReport($this->db, App::settings());
      $snapshot = $report->snapshot();
      $quotes = array_map(static fn(array $row): array => array_combine($snapshot['columns'], $row), $snapshot['rows']);
      $reconciliation = new QuoteLedgerReconciliation();
      $entries = $reconciliation->parse((string) $file['tmp_name'], (string) ($file['name'] ?? ''));
      $this->jsonOk($reconciliation->reconcile($entries, $quotes));
    } catch (\RuntimeException $exception) {
      $this->jsonFail($exception->getMessage());
    } catch (\Throwable $exception) {
      error_log('[quote-ledger] ' . $exception->getMessage());
      $this->jsonFail('No se pudo conciliar el auxiliar.');
    }
  }
}
