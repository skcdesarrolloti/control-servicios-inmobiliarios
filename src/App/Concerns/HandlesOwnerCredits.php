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
    foreach (['contract_end', 'gross', 'ded_comision', 'ded_garantia', 'ded_poliza_servicios', 'ded_otros', 'requested_amount', 'requested_term', 'purpose', 'vacancy', 'amount', 'term', 'rate', 'disbursed_at', 'siniestro', 'has_credits', 'other_obligations', 'note'] as $key) {
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
      $this->jsonOk(['html' => (new OwnerCreditView())->report($this->ownerCreditService()->report($filters), true, $this->canUseDashboardAction('owner_credit_manage'))]);
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
      // Con lo que el analista tiene en pantalla, para que el documento coincida con el liquidador.
      $work = $this->ownerCreditService()->workspace($id, $this->ownerCreditInput());
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

  /**
   * PDF de simulación desde "Nueva solicitud", sin registrar la solicitud. La simulación queda en el historial de
   * los contratos del propietario y de sus inmuebles.
   */
  public function ajax_handler_owner_credit_simulation_pdf(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para crear créditos.');
    try {
      OwnerCreditUsuryRate::sync(App::settings());
      $service = $this->ownerCreditService();
      $actor = $this->ticketCompletionActor();
      $work = $service->simulation(
        sanitize_text_field(wp_unslash((string) ($_POST['document'] ?? ''))),
        (int) ($_POST['contract_id'] ?? 0),
        OwnerCreditService::money(sanitize_text_field(wp_unslash((string) ($_POST['amount'] ?? '')))),
        (int) ($_POST['term'] ?? 0),
        sanitize_text_field(wp_unslash((string) ($_POST['purpose'] ?? ''))),
        sanitize_key((string) ($_POST['owner_key'] ?? '')),
        $actor
      );
      $bytes = \SCM\Modules\OwnerCredits\OwnerCreditPdf::bytes($work, (string) ($actor['name'] ?? ''));
      $service->recordSimulation($work, $actor);
    } catch (\InvalidArgumentException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo generar la simulación.');
    }
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="simulacion-credito-' . preg_replace('/[^0-9A-Za-z-]/', '', (string) $work['credit']['contract_code']) . '.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
  }

  /** Simulación de abono extraordinario (no registra nada): compara reducir plazo y reducir cuota. */
  public function ajax_handler_owner_credit_prepayment(): void
  {
    $this->verifyCsrf();
    if (!$this->canViewOwnerCredits()) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      $result = $this->ownerCreditService()->prepayment(
        (int) ($_POST['credit_id'] ?? 0),
        sanitize_text_field(wp_unslash((string) ($_POST['prepay_date'] ?? ''))),
        OwnerCreditService::money(sanitize_text_field(wp_unslash((string) ($_POST['prepay_amount'] ?? ''))))
      );
      $this->jsonOk(['html' => OwnerCreditView::prepaymentResults($result)]);
    } catch (\InvalidArgumentException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo simular el abono.');
    }
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

  /** Cambia el contrato principal de una solicitud pendiente por otro contrato de la persona. */
  public function ajax_handler_owner_credit_contract(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_decide', 'Tu cargo no tiene permiso para cambiar el contrato principal del crédito.');
    try {
      $credit = $this->ownerCreditService()->changeContract((int) ($_POST['credit_id'] ?? 0), (int) ($_POST['contract_id'] ?? 0), $this->ticketCompletionActor());
      $this->jsonOk(['credit_id' => (int) $credit['id'], 'message' => 'Contrato principal: #' . $credit['contract_code'] . '.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo cambiar el contrato principal.');
    }
  }

  /** Cambia a quién es una solicitud pendiente, entre los propietarios del contrato principal. */
  public function ajax_handler_owner_credit_owner(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_decide', 'Tu cargo no tiene permiso para cambiar el beneficiario del crédito.');
    try {
      $credit = $this->ownerCreditService()->changeOwner((int) ($_POST['credit_id'] ?? 0), sanitize_key((string) ($_POST['owner_key'] ?? '')), $this->ticketCompletionActor());
      $this->jsonOk(['credit_id' => (int) $credit['id'], 'message' => 'Beneficiario actualizado: ' . $credit['owner_name'] . '.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo cambiar el beneficiario.');
    }
  }

  /** "Nueva solicitud": contratos donde el documento es de un propietario, con esa persona como beneficiaria. */
  public function ajax_handler_owner_credit_contracts(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para crear créditos.');
    try {
      // El primero es el de vigencia más larga: el formulario lo preselecciona.
      $service = $this->ownerCreditService();
      $document = sanitize_text_field(wp_unslash((string) ($_POST['document'] ?? '')));
      $rows = $service->ownerPortfolio($document);
      // Cupo preaprobado con todos los contratos de la persona, menos el saldo de sus créditos vigentes.
      $preapproval = \SCM\Modules\OwnerCredits\OwnerCreditCalculator::preapproval($rows, OwnerCreditSettings::read(App::settings()));
      $preapproval['outstanding'] = $rows === [] ? 0.0 : $service->outstandingByDocument($document, array_map(static fn(array $row): string => (string) ($row['owner']['owner_id'] ?? ''), $rows));
      $preapproval['available'] = max(0.0, $preapproval['amount'] - $preapproval['outstanding']);
      $this->jsonOk(['contracts' => $rows, 'preapproval' => $preapproval, 'checks' => $service->requestChecks($rows, $preapproval), 'disclaimer' => OwnerCreditService::DISCLAIMER]);
    } catch (\InvalidArgumentException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo buscar contratos.');
    }
  }

  public function ajax_handler_owner_credit_void(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para anular solicitudes.');
    try {
      $this->ownerCreditService()->voidRequest((int) ($_POST['credit_id'] ?? 0));
      $this->jsonOk(['message' => 'Solicitud anulada.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo anular la solicitud.');
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
        '',
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
        foreach (['usury_auto', 'usury_annual', 'usury_updated_at', 'reference_rate', 'policy_discount', 'no_mandate_deduction', 'income_months', 'min_remaining_months', 'max_term_months', 'usable_income_factor', 'min_coverage', 'notify_owner_email', 'notify_owner_whatsapp', 'notify_staff_whatsapp'] as $key) {
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
