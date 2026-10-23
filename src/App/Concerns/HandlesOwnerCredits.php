<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\OwnerCredits\OwnerCreditService;
use SCM\Modules\OwnerCredits\OwnerCreditSettings;
use SCM\Modules\OwnerCredits\OwnerCreditUsuryRate;
use SCM\Modules\OwnerCredits\OwnerCreditView;

/** Liquidador de créditos a propietarios (desde la cotización) y Métricas › Créditos. */
trait HandlesOwnerCredits
{
  private function ownerCreditService(): OwnerCreditService
  {
    return new OwnerCreditService($this->db, App::settings());
  }

  /** Ver el liquidador: quien ve créditos en métricas o puede gestionar el financiamiento de la cotización. */
  private function canViewOwnerCredits(): bool
  {
    return $this->canAccessDashboardTab('credito_propietario') || $this->canUseDashboardAction('quote_financing');
  }

  /** @return array<string,string> */
  private function ownerCreditInput(): array
  {
    $input = [];
    foreach (['contract_end', 'gross', 'ded_comision', 'ded_garantia', 'ded_poliza_servicios', 'ded_otros', 'requested_amount', 'requested_term', 'purpose', 'vacancy', 'amount', 'term', 'rate', 'note'] as $key) {
      if (isset($_POST[$key])) $input[$key] = trim(sanitize_textarea_field(wp_unslash((string) $_POST[$key])));
    }
    return $input;
  }

  private function ownerCreditFail(\Throwable $error, string $fallback): void
  {
    if ($error instanceof \DomainException) $this->jsonFail($error->getMessage());
    error_log('[owner-credit] ' . $error->getMessage());
    $this->jsonFail($fallback);
  }

  public function ajax_handler_owner_credit_report(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('credito_propietario')) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      $filters = [];
      foreach (['status', 'from', 'to', 'search'] as $key) $filters[$key] = trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
      $this->jsonOk(['html' => (new OwnerCreditView())->report($this->ownerCreditService()->report($filters), true)]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudieron cargar los créditos.');
    }
  }

  public function ajax_handler_owner_credit_open(): void
  {
    $this->verifyCsrf();
    if (!$this->canViewOwnerCredits()) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      OwnerCreditUsuryRate::sync(App::settings());
      $service = $this->ownerCreditService();
      $service->ensureSchema();
      $id = (int) ($_POST['credit_id'] ?? 0);
      if ($id <= 0) $id = $service->ensureForQuote((int) ($_POST['quote_id'] ?? 0), $this->ticketCompletionActor());
      $work = $service->workspace($id);
      $this->jsonOk([
        'credit_id' => $id,
        'title' => 'Crédito #' . $id . ' · ' . OwnerCreditService::STATUSES[$work['credit']['status']],
        'html' => (new OwnerCreditView())->workspace($work, $this->canUseDashboardAction('owner_credit_decide'), $this->canUseDashboardAction('owner_credit_manage')),
      ]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo abrir el liquidador.');
    }
  }

  /** PDF interno del crédito (solicitud, evaluación y decisión); solo funcionarios, no se envía al propietario. */
  public function ajax_handler_owner_credit_pdf(): void
  {
    $this->verifyCsrf();
    if (!$this->canViewOwnerCredits()) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      $id = (int) ($_POST['credit_id'] ?? 0);
      $work = $this->ownerCreditService()->workspace($id);
      $bytes = \SCM\Modules\OwnerCredits\OwnerCreditPdf::bytes($work, (string) ($this->ticketCompletionActor()['name'] ?? ''));
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo generar el PDF del crédito.');
    }
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="credito-propietario-' . $id . '.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
  }

  public function ajax_handler_owner_credit_simulate(): void
  {
    $this->verifyCsrf();
    if (!$this->canViewOwnerCredits()) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      $work = $this->ownerCreditService()->workspace((int) ($_POST['credit_id'] ?? 0), $this->ownerCreditInput());
      $view = new OwnerCreditView();
      $this->jsonOk(['results_html' => $view->results($work), 'schedule_html' => $view->schedule($work)]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo calcular el crédito.');
    }
  }

  public function ajax_handler_owner_credit_decide(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_decide', 'Tu cargo no tiene permiso para aprobar o rechazar créditos.');
    try {
      $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
      $result = $this->ownerCreditService()->decide((int) ($_POST['credit_id'] ?? 0), $decision, $this->ownerCreditInput(), $this->ticketCompletionActor());
      $q = $result['queued'];
      $this->jsonOk(['message' => ($decision === 'aprobado' ? 'Crédito aprobado.' : 'Crédito rechazado.') . ' Propietario: ' . $q['email'] . ' correo(s), ' . $q['whatsapp'] . ' WhatsApp. Funcionarios: ' . $q['internal'] . ' correo(s), ' . $q['internal_whatsapp'] . ' WhatsApp.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo guardar la decisión.');
    }
  }

  /** Propietarios del contrato elegido: a quién se le puede hacer el crédito. */
  public function ajax_handler_owner_credit_owners(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para crear créditos.');
    try {
      $owners = array_map(static fn(array $owner): array => array_intersect_key($owner, array_flip(['key', 'name', 'document', 'email', 'phone'])), $this->ownerCreditService()->contractOwners((int) ($_POST['contract_id'] ?? 0)));
      $this->jsonOk(['owners' => $owners]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudieron cargar los propietarios del contrato.');
    }
  }

  /** Búsqueda de contratos para "Solicitud de crédito" (misma búsqueda de Crear caso). */
  public function ajax_handler_owner_credit_contracts(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para crear créditos.');
    try {
      $contracts = $this->panelCaseService()->searchPanelContracts(trim(sanitize_text_field(wp_unslash((string) ($_POST['query'] ?? '')))), sanitize_key((string) ($_POST['by'] ?? '')));
      $rows = [];
      foreach ($contracts as $contract) {
        $end = OwnerCreditService::timestamp($contract['fin_contrato'] ?? '');
        $rows[] = [
          'id' => (int) ($contract['_ID'] ?? 0), 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
          'direccion' => (string) ($contract['direccion'] ?? ''), 'estado' => (string) ($contract['estado'] ?? ''),
          'propietario' => (string) ($contract['propietario'] ?? ''), 'arrendatario' => (string) ($contract['arrendatario'] ?? ''),
          'fin' => $end > 0 ? date('d/m/Y', $end) : '', 'fin_iso' => $end > 0 ? date('Y-m-d', $end) : '', 'max_months' => \SCM\Modules\OwnerCredits\OwnerCreditCalculator::monthsUntil(time(), $end),
        ];
      }
      $this->jsonOk(['contracts' => $rows]);
    } catch (\InvalidArgumentException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo buscar contratos.');
    }
  }

  public function ajax_handler_owner_credit_close(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para cerrar o anular créditos.');
    try {
      $status = sanitize_key((string) ($_POST['status'] ?? ''));
      $this->ownerCreditService()->changeStatus((int) ($_POST['credit_id'] ?? 0), $status, $this->ownerCreditInput()['note'] ?? '', $this->ticketCompletionActor());
      $this->jsonOk(['message' => $status === 'pagado' ? 'Crédito marcado como pagado.' : 'Crédito anulado.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo actualizar el crédito.');
    }
  }

  public function ajax_handler_owner_credit_create(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para crear créditos.');
    if (!$this->canAccessDashboardTab('credito_propietario')) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      $id = $this->ownerCreditService()->createManual(
        (int) ($_POST['contract_id'] ?? 0),
        OwnerCreditService::money(sanitize_text_field(wp_unslash((string) ($_POST['amount'] ?? '')))),
        (int) ($_POST['term'] ?? 0),
        sanitize_text_field(wp_unslash((string) ($_POST['purpose'] ?? ''))),
        $this->ticketCompletionActor(),
        sanitize_text_field(wp_unslash((string) ($_POST['contract_end'] ?? ''))),
        sanitize_key((string) ($_POST['owner_key'] ?? '')),
        sanitize_key((string) ($_POST['beneficiary_type'] ?? 'propietario'))
      );
      $this->jsonOk(['credit_id' => $id, 'message' => 'Solicitud registrada.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo crear el crédito.');
    }
  }

  public function ajax_handler_owner_credit_settings(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_configure', 'Tu cargo no tiene permiso para configurar créditos.');
    try {
      $view = new OwnerCreditView();
      $mode = (string) ($_POST['mode'] ?? '');
      if ($mode === 'save') {
        // Se parte de lo guardado para no perder los datos de la última sincronización.
        $input = OwnerCreditSettings::read(App::settings());
        foreach (['usury_auto', 'usury_annual', 'usury_updated_at', 'reference_rate', 'policy_discount', 'no_mandate_deduction', 'income_months', 'min_remaining_months', 'notify_owner_email', 'notify_owner_whatsapp', 'notify_staff_whatsapp'] as $key) {
          if (isset($_POST[$key])) $input[$key] = sanitize_text_field(wp_unslash((string) $_POST[$key]));
        }
        $saved = OwnerCreditSettings::save(App::settings(), $input, Auth::userId());
        if ($saved['usury_auto']) OwnerCreditUsuryRate::sync(App::settings(), true);
        $this->jsonOk(['message' => 'Configuración de créditos guardada.', 'html' => $view->settingsForm(OwnerCreditSettings::read(App::settings()))]);
      }
      $settings = OwnerCreditUsuryRate::sync(App::settings(), $mode === 'refresh');
      $this->jsonOk(['html' => $view->settingsForm($settings), 'message' => $mode === 'refresh' ? ((string) $settings['usury_sync_error'] === '' ? 'Tasa de usura actualizada.' : 'No se pudo actualizar la tasa.') : '']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo guardar la configuración.');
    }
  }

  /** Formulario de "Solicitud de crédito" (no requiere permiso de configuración). */
  public function ajax_handler_owner_credit_new_form(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para crear créditos.');
    $this->jsonOk(['html' => (new OwnerCreditView())->newForm()]);
  }
}
