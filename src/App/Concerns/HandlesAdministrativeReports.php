<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\AdministrativeReports\AdministrativeReportsService;
use SCM\Modules\AdministrativeReports\AdministrativeReportsView;
use SCM\Modules\AdministrativeReports\BillingAccountDocument;
use SCM\Support\FuncionarioOptions;

/**
 * Actividades administrativas › Reportes administrativos (antes plugin "Reportes Administrativos SKC").
 * La pestaña del menú (reportes_administrativos_pendientes) da acceso al panel; cada pestaña interna y
 * cada acción se habilitan con el catálogo de permisos por acción (grupo Actividades administrativas).
 */
trait HandlesAdministrativeReports
{
  private function adminReportsService(): AdministrativeReportsService
  {
    return new AdministrativeReportsService($this->db);
  }

  /** @return array<int,string> Pestañas internas permitidas, en orden. */
  private function adminReportsTabs(): array
  {
    $tabs = [];
    if ($this->canUseDashboardAction('admin_report_pending_view') || $this->canUseDashboardAction('admin_report_review')) $tabs[] = 'pendientes';
    if ($this->canUseDashboardAction('admin_report_own')) $tabs[] = 'mis_reportes';
    if ($this->canUseDashboardAction('admin_report_payments')) $tabs[] = 'consolidado';
    if ($this->canUseDashboardAction('admin_report_accounts')) $tabs[] = 'cuentas';
    return $tabs;
  }

  private function renderAdministrativeReportsPanel(string $pendingHtml): string
  {
    $tabs = $this->adminReportsTabs();
    if (!in_array('pendientes', $tabs, true)) $pendingHtml = '';
    $manage = $this->canUseDashboardAction('admin_report_manage');
    // Funcionarios activos de cualquier cargo: solo para los selectores de quien gestiona o paga reportes.
    $funcionarios = $manage || in_array('consolidado', $tabs, true) ? FuncionarioOptions::panelFuncionarios($this->db, null, 'employee', []) : [];
    return (new AdministrativeReportsView())->panel($tabs, $pendingHtml, $funcionarios, Auth::employeeId(), ['manage' => $manage]);
  }

  private function adminReportsRequire(string $action, string $message): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('reportes_administrativos_pendientes')) $this->jsonFail('No tienes acceso a Reportes administrativos.');
    $this->requireDashboardAction($action, $message);
  }

  private function adminReportsFail(\Throwable $error, string $fallback): void
  {
    if ($error instanceof \DomainException) $this->jsonFail($error->getMessage());
    error_log('[admin-reports] ' . $error->getMessage());
    $this->jsonFail($fallback);
  }

  private function adminReportsInput(string $key): string
  {
    return trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
  }

  /** @param array<int,string> $keys @return array<string,string> */
  private function adminReportsFilters(array $keys): array
  {
    $filters = [];
    foreach ($keys as $key) $filters[$key] = $this->adminReportsInput($key);
    return $filters;
  }

  /** Funcionario sobre el que se trabaja en "Mis reportes": el propio, u otro si se pueden gestionar reportes. */
  private function adminReportsEmployee(): string
  {
    $own = Auth::employeeId();
    $requested = $this->adminReportsInput('employee');
    if ($requested === '' || $requested === $own) return $own;
    if (!$this->canUseDashboardAction('admin_report_manage')) $this->jsonFail('Solo puedes ver tus propios reportes.');
    return $requested;
  }

  /** @return array<int,int> */
  private function adminReportsIds(): array
  {
    return AdministrativeReportsService::parseIds(wp_unslash($_POST['ids'] ?? ''));
  }

  public function ajax_handler_admin_reports_own(): void
  {
    $this->adminReportsRequire('admin_report_own', 'Tu cargo no tiene permiso para ver reportes propios.');
    try {
      $service = $this->adminReportsService();
      $employee = $this->adminReportsEmployee();
      if ($employee === '') $this->jsonFail('Tu usuario no tiene un id de empleado asociado.');
      $state = $this->adminReportsInput('state');
      $listState = in_array($state, AdministrativeReportsService::OWN_STATES, true) ? $state : 'no_exportados';
      $state = $state === 'cuentas' ? 'cuentas' : $listState;
      $data = $service->ownReports($employee, $listState, $this->adminReportsFilters(['consecutivo', 'tipo', 'contrato', 'inmueble', 'arrendatario', 'ticket']));
      $accounts = $service->accounts([], $employee)['items'];
      $this->jsonOk(['html' => (new AdministrativeReportsView())->ownResults($data, $state, $this->canUseDashboardAction('admin_report_manage'), $accounts, $employee === Auth::employeeId())]);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudieron cargar los reportes.');
    }
  }

  public function ajax_handler_admin_reports_account_create(): void
  {
    $this->adminReportsRequire('admin_report_own', 'Tu cargo no tiene permiso para crear cuentas de cobro.');
    try {
      $result = $this->adminReportsService()->createAccount($this->adminReportsEmployee(), $this->adminReportsIds());
      $this->jsonOk($result + ['message' => 'Cuenta de cobro ' . $result['numero'] . ' creada.']);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudo crear la cuenta de cobro.');
    }
  }

  public function ajax_handler_admin_reports_values(): void
  {
    $this->adminReportsRequire('admin_report_manage', 'Tu cargo no tiene permiso para editar valores de reportes.');
    try {
      $result = $this->adminReportsService()->updateValues(
        (int) ($_POST['id'] ?? 0),
        AdministrativeReportsService::money($_POST['servicio'] ?? 0),
        AdministrativeReportsService::money($_POST['transporte'] ?? 0)
      );
      $this->jsonOk($result + ['message' => 'Valores actualizados.']);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudieron actualizar los valores.');
    }
  }

  public function ajax_handler_admin_reports_report_form(): void
  {
    $this->adminReportsRequire('admin_report_manage', 'Tu cargo no tiene permiso para crear reportes administrativos.');
    try {
      $service = $this->adminReportsService();
      $ticketId = (int) preg_replace('/\D+/', '', $this->adminReportsInput('id_ticket'));
      $ticket = $service->ticket($ticketId);
      if ($ticket === null) $this->jsonFail('No se encontró el caso #' . $ticketId . '.');
      $employee = $this->adminReportsEmployee();
      $mode = $this->adminReportsInput('form') === 'transporte' ? 'transporte' : 'proceso';
      $this->jsonOk([
        'title' => $mode === 'transporte' ? 'Añadir transporte por visita' : 'Crear reporte administrativo',
        'html' => (new AdministrativeReportsView())->reportForm($mode, $ticket, $service->categories(), $service->suggestedValues(), $employee, $service->employee($employee)['nombre']),
      ]);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudo abrir el formulario.');
    }
  }

  public function ajax_handler_admin_reports_report_create(): void
  {
    $this->adminReportsRequire('admin_report_manage', 'Tu cargo no tiene permiso para crear reportes administrativos.');
    try {
      $service = $this->adminReportsService();
      $employee = $this->adminReportsEmployee();
      $input = $this->adminReportsFilters(['form', 'id_ticket', 'categoria', 'servicio', 'transporte', 'cerrar_ticket']);
      $input['descripcion'] = trim(sanitize_textarea_field(wp_unslash((string) ($_POST['descripcion'] ?? ''))));
      $result = $service->createReport($input, $employee, $service->employee($employee)['nombre'], $this->ticketCompletionActor());
      $this->jsonOk($result + ['message' => 'Reporte #' . $result['id'] . ' creado.']);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudo crear el reporte.');
    }
  }

  public function ajax_handler_admin_reports_consolidated(): void
  {
    $this->adminReportsRequire('admin_report_payments', 'Tu cargo no tiene permiso para ver el consolidado de pagos.');
    try {
      $service = $this->adminReportsService();
      $state = $this->adminReportsInput('state') === 'pagados' ? 'pagados' : 'no_pagados';
      $data = $service->consolidated($state, $this->adminReportsFilters(['consecutivo', 'ticket', 'contrato', 'solicitud', 'inmueble', 'funcionario']), (int) ($_POST['page'] ?? 1));
      $this->jsonOk(['html' => (new AdministrativeReportsView())->consolidatedResults($data, $state, $service->backfillPending()), 'page' => $data['page']]);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudo cargar el consolidado.');
    }
  }

  public function ajax_handler_admin_reports_pay_reports(): void
  {
    $this->adminReportsRequire('admin_report_payments', 'Tu cargo no tiene permiso para marcar reportes como pagados.');
    try {
      $updated = $this->adminReportsService()->markReportsPaid($this->adminReportsIds());
      $this->jsonOk(['updated' => $updated, 'message' => $updated === 1 ? 'Reporte marcado como pagado.' : $updated . ' reportes marcados como pagados.']);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudieron marcar los reportes.');
    }
  }

  public function ajax_handler_admin_reports_backfill(): void
  {
    $this->adminReportsRequire('admin_report_payments', 'Tu cargo no tiene permiso para completar datos de reportes.');
    try {
      $updated = $this->adminReportsService()->backfill();
      $this->jsonOk(['updated' => $updated, 'message' => 'Datos completados. Reportes actualizados: ' . $updated . '.']);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No fue posible completar los datos.');
    }
  }

  public function ajax_handler_admin_reports_accounts(): void
  {
    $this->adminReportsRequire('admin_report_accounts', 'Tu cargo no tiene permiso para ver cuentas de cobro.');
    try {
      $data = $this->adminReportsService()->accounts($this->adminReportsFilters(['numero', 'funcionario', 'desde', 'hasta', 'estado']));
      $this->jsonOk(['html' => (new AdministrativeReportsView())->accountsResults($data, true)]);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudieron cargar las cuentas de cobro.');
    }
  }

  public function ajax_handler_admin_reports_pay_accounts(): void
  {
    $this->adminReportsRequire('admin_report_accounts', 'Tu cargo no tiene permiso para marcar cuentas de cobro como pagadas.');
    try {
      $result = $this->adminReportsService()->markAccountsPaid($this->adminReportsIds());
      $this->jsonOk($result + ['message' => 'Cuentas pagadas: ' . $result['accounts'] . '. Reportes actualizados: ' . $result['reports'] . '.']);
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudieron marcar las cuentas.');
    }
  }

  /** Cuenta visible para quien la creó (o su funcionario), quien gestiona reportes o quien administra cuentas. @return array<string,mixed> */
  private function adminReportsAccount(): array
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('reportes_administrativos_pendientes')) $this->jsonFail('No tienes acceso a Reportes administrativos.');
    $account = $this->adminReportsService()->account((int) ($_POST['id'] ?? 0));
    if ($account === null) $this->jsonFail('Cuenta de cobro no encontrada.');
    $own = Auth::employeeId() !== '' && $account['id_empleado'] === Auth::employeeId() && $this->canUseDashboardAction('admin_report_own');
    if (!$own && !$this->canUseDashboardAction('admin_report_accounts') && !$this->canUseDashboardAction('admin_report_manage')) {
      $this->jsonFail('No tienes permiso para ver esta cuenta de cobro.');
    }
    return $account;
  }

  public function ajax_handler_admin_reports_account(): void
  {
    $account = $this->adminReportsAccount();
    $this->jsonOk([
      'title' => 'Cuenta de cobro ' . $account['numero'],
      'html' => (new AdministrativeReportsView())->accountDetail($account, $this->canUseDashboardAction('admin_report_accounts')),
    ]);
  }

  public function ajax_handler_admin_reports_account_pdf(): void
  {
    $account = $this->adminReportsAccount();
    try {
      $bytes = BillingAccountDocument::pdf($account, $this->adminReportsService()->employee((string) $account['id_empleado']));
    } catch (\Throwable $error) {
      $this->adminReportsFail($error, 'No se pudo generar el PDF de la cuenta de cobro.');
    }
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="cuenta_cobro_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $account['numero']) . '.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
  }

  public function ajax_handler_admin_reports_account_excel(): void
  {
    $account = $this->adminReportsAccount();
    $content = BillingAccountDocument::excel($account, $this->adminReportsService()->employee((string) $account['id_empleado']));
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cuenta_cobro_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $account['numero']) . '.xls"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $content;
    exit;
  }
}
