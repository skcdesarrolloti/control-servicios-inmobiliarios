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

  /** Vista interna del pagaré (credito-pagare.php sin token): los mismos que ven el liquidador. */
  public function canViewOwnerCreditDocuments(): bool
  {
    return $this->canViewOwnerCredits();
  }

  /** @return array<string,string> */
  private function ownerCreditInput(): array
  {
    $input = [];
    foreach (['contract_end', 'gross', 'ded_comision', 'ded_garantia', 'ded_poliza_servicios', 'ded_otros', 'requested_amount', 'requested_term', 'purpose', 'vacancy', 'amount', 'term', 'rate', 'rate_discount', 'disbursed_at', 'siniestro', 'has_credits', 'other_obligations', 'note'] as $key) {
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
      foreach (['status', 'accounting', 'from', 'to', 'search'] as $key) $filters[$key] = trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
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
        'title' => 'Crédito #' . $id . ' · ' . OwnerCreditService::STATUSES[$work['credit']['status']] . (isset(OwnerCreditService::STAGES[$work['stage']]) && $work['stage'] !== 'estudio' ? ' · ' . OwnerCreditService::STAGES[$work['stage']] : ''),
        'html' => (new OwnerCreditView($this->canUseDashboardAction('owner_credit_configure')))->workspace($work, $this->canUseDashboardAction('owner_credit_decide'), $this->canUseDashboardAction('owner_credit_manage')),
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
      $view = new OwnerCreditView($this->canUseDashboardAction('owner_credit_configure'));
      $this->jsonOk(['results_html' => $view->results($work), 'results_below_html' => $view->resultsBelow($work), 'schedule_html' => $view->schedule($work)]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo calcular el crédito.');
    }
  }

  /**
   * Tasas desde el liquidador: "usury" vuelve a consultar la usura en datos.gov.co (quien decide créditos) y, con
   * permiso de configurar, baja la tasa de referencia si la nueva usura la deja por encima; "reference" cambia la tasa
   * de referencia de Configuración (solo con permiso de configurar créditos).
   */
  public function ajax_handler_owner_credit_rate(): void
  {
    $this->verifyCsrf();
    $percent = static fn(float $rate, int $decimals = 2): string => number_format($rate * 100, $decimals, ',', '.') . ' %';
    try {
      if (sanitize_key((string) ($_POST['mode'] ?? '')) === 'reference') {
        $this->requireDashboardAction('owner_credit_configure', 'Tu cargo no tiene permiso para cambiar la tasa de referencia.');
        $value = sanitize_text_field(wp_unslash((string) ($_POST['reference_rate'] ?? '')));
        if (OwnerCreditService::rate($value) <= 0) throw new \DomainException('Escribe la tasa de referencia mensual, por ejemplo 2,05.');
        $settings = OwnerCreditSettings::read(App::settings());
        $before = (float) $settings['reference_rate'];
        // Con "%" se lee siempre como porcentaje (0,9 = 0,9 % y no 90 %).
        $settings['reference_rate'] = rtrim($value, '% ') . '%';
        $saved = OwnerCreditSettings::save(App::settings(), $settings, Auth::userId());
        error_log('[owner-credit-rate] reference ' . $before . ' -> ' . $saved['reference_rate'] . ' by ' . Auth::userId());
        $this->jsonOk(['message' => 'Tasa de referencia guardada: ' . $percent($before, 4) . ' → ' . $percent((float) $saved['reference_rate'], 4) . ' N.M.V.']);
      }
      $this->requireDashboardAction('owner_credit_decide', 'Tu cargo no tiene permiso para actualizar la tasa de usura.');
      if (!OwnerCreditSettings::read(App::settings())['usury_auto']) throw new \DomainException('La usura está en modo manual: cámbiala en Configuración › Créditos a propietarios.');
      $settings = OwnerCreditUsuryRate::sync(App::settings(), true);
      if ((string) $settings['usury_sync_error'] !== '') throw new \DomainException('No se pudo actualizar la usura: ' . $settings['usury_sync_error'] . ' Se conserva la última tasa.');
      $message = 'Usura actualizada: ' . $percent((float) $settings['usury_annual']) . ' E.A.' . OwnerCreditService::usuryPeriod($settings) . '.';
      if ($this->canUseDashboardAction('owner_credit_configure')) {
        $capped = OwnerCreditUsuryRate::capReference(App::settings(), $settings, Auth::userId());
        if ($capped !== null) {
          error_log('[owner-credit-rate] reference capped ' . $capped['before'] . ' -> ' . $capped['after'] . ' by ' . Auth::userId());
          $message .= ' Tasa de referencia bajada a la usura: ' . $percent($capped['before'], 4) . ' → ' . $percent($capped['after'], 4) . ' N.M.V.';
        }
      } elseif ((float) $settings['reference_rate'] > OwnerCreditUsuryRate::monthly($settings) + 1e-9) {
        $message .= ' La tasa de referencia sigue sobre la usura: pídele a quien configura créditos que la baje.';
      }
      $this->jsonOk(['message' => $message]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo actualizar la tasa.');
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
      $next = $result['next'];
      $message = $decision === 'aprobado'
        ? 'Crédito aprobado. ' . ($next['response'] !== null
          ? 'Ya tenía el pagaré firmado: se envió la respuesta con ' . count($next['response']['documents']) . ' documento(s).'
          : ($next['promissory'] !== [] ? 'Pagaré enviado para firmar a ' . implode('; ', $next['promissory']) . '. La respuesta sale cuando lo firme.' : ''))
        : 'Crédito rechazado. Propietario: ' . $q['email'] . ' correo(s), ' . $q['whatsapp'] . ' WhatsApp.';
      $this->jsonOk(['message' => trim($message . ' Funcionarios: ' . $q['internal'] . ' correo(s), ' . $q['internal_whatsapp'] . ' WhatsApp. ' . implode(' ', $next['errors']))]);
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

  /** Quiénes firman el pagaré de este crédito: uno o varios de los propietarios del contrato principal. */
  public function ajax_handler_owner_credit_promissory_signers(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_decide', 'Tu cargo no tiene permiso para elegir quién firma el pagaré.');
    try {
      $keys = array_map(static fn($key): string => sanitize_key((string) $key), (array) wp_unslash($_POST['signers'] ?? []));
      $this->ownerCreditService()->setPromissorySigners((int) ($_POST['credit_id'] ?? 0), $keys, $this->ticketCompletionActor());
      $this->jsonOk(['credit_id' => (int) ($_POST['credit_id'] ?? 0), 'message' => 'Firmantes del pagaré actualizados.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo guardar quién firma el pagaré.');
    }
  }

  /** Envía (o reenvía) el pagaré y la carta de instrucciones a quienes aún no lo tienen firmado. */
  public function ajax_handler_owner_credit_promissory_send(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_decide', 'Tu cargo no tiene permiso para enviar el pagaré.');
    try {
      $channels = array_map(static fn($channel): string => sanitize_key((string) $channel), (array) wp_unslash($_POST['channels'] ?? []));
      $result = $this->ownerCreditService()->sendPromissory((int) ($_POST['credit_id'] ?? 0), $channels, $this->ticketCompletionActor());
      $message = $result['sent'] !== [] ? 'Pagaré enviado a ' . implode('; ', $result['sent']) . '.' : 'Todos los firmantes ya tienen el pagaré firmado.';
      if ($result['errors'] !== []) $message .= ' ' . implode(' ', $result['errors']);
      $this->jsonOk(['credit_id' => (int) ($_POST['credit_id'] ?? 0), 'message' => $message]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo enviar el pagaré.');
    }
  }

  /** PDF del pagaré firmado (verificado contra su HMAC), para el visor del liquidador. */
  public function ajax_handler_owner_credit_promissory_pdf(): void
  {
    $this->verifyCsrf();
    if (!$this->canViewOwnerCredits()) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      $promissory = $this->ownerCreditService()->promissory();
      $id = (int) ($_POST['promissory_id'] ?? 0);
      $bytes = $promissory->pdf($promissory->find($id));
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo abrir el pagaré firmado.');
    }
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="pagare-' . \SCM\Modules\OwnerCredits\OwnerCreditPromissoryService::number($id) . '-firmado.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
  }

  /** Envía (o reenvía) la respuesta del crédito aprobado: carta de aprobación, pagaré firmado y plan de pagos. */
  public function ajax_handler_owner_credit_response_send(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_decide', 'Tu cargo no tiene permiso para enviar la respuesta del crédito.');
    try {
      $id = (int) ($_POST['credit_id'] ?? 0);
      $q = $this->ownerCreditService()->sendResponse($id, $this->ticketCompletionActor());
      $this->jsonOk(['credit_id' => $id, 'message' => 'Respuesta enviada con ' . count($q['documents']) . ' documento(s). Propietario: ' . $q['email'] . ' correo(s), ' . $q['whatsapp'] . ' WhatsApp. Funcionarios: ' . $q['internal'] . ' correo(s), ' . $q['internal_whatsapp'] . ' WhatsApp.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo enviar la respuesta del crédito.');
    }
  }

  /** Carta de aprobación o condiciones con plan de pagos en PDF, para el visor del liquidador. */
  public function ajax_handler_owner_credit_document(): void
  {
    $this->verifyCsrf();
    if (!$this->canViewOwnerCredits()) $this->jsonFail('No tienes permiso para ver créditos.');
    $id = (int) ($_POST['credit_id'] ?? 0);
    $kind = sanitize_key((string) ($_POST['kind'] ?? ''));
    try {
      $bytes = $this->ownerCreditService()->responsePdf($id, $kind);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo generar el documento del crédito.');
    }
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . (\SCM\Modules\OwnerCredits\OwnerCreditResponseDocuments::FILENAMES[$kind] ?? 'documento') . '-credito-' . $id . '.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
  }

  /** Anula un pagaré con nota (la persona tendrá que firmar uno nuevo). */
  public function ajax_handler_owner_credit_promissory_void(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('owner_credit_manage', 'Tu cargo no tiene permiso para anular pagarés.');
    try {
      $this->ownerCreditService()->voidPromissory((int) ($_POST['promissory_id'] ?? 0), sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))), $this->ticketCompletionActor());
      $this->jsonOk(['credit_id' => (int) ($_POST['credit_id'] ?? 0), 'message' => 'Pagaré anulado.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo anular el pagaré.');
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
      // Con letras se busca por nombre: se devuelven las personas para elegir y luego se busca por su documento.
      if (preg_match('/\p{L}{2,}/u', $document) === 1) {
        $people = $service->ownersByName($document);
        if ($people !== [] || preg_match('/^[0-9A-Z-]{4,20}$/', strtoupper(preg_replace('/[\s.]+/', '', $document) ?? '')) !== 1) $this->jsonOk(['people' => $people]);
      }
      // Cupo personal con todos los contratos de la persona (según su %) y, por contrato, el de reparaciones (100 %).
      $this->jsonOk($service->requestPreapproval($document) + ['repair_purpose' => OwnerCreditSettings::REPAIR_PURPOSE, 'property_purposes' => OwnerCreditSettings::PROPERTY_PURPOSES, 'disclaimer' => OwnerCreditService::DISCLAIMER]);
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

  /**
   * Grupos de la alerta de créditos, según las alertas marcadas para el cargo.
   * @param list<string> $keys @return list<array<string,mixed>>
   */
  private function ownerCreditAlertGroups(array $keys): array
  {
    $pending = $this->ownerCreditService()->pendingAttention();
    $age = static function (int $ts): string {
      $days = max(0, (int) floor((time() - $ts) / 86400));
      return $days === 0 ? 'Hoy' : 'Hace ' . $days . ' día' . ($days === 1 ? '' : 's');
    };
    $where = static fn(array $row): string => 'Contrato #' . $row['contract_code'] . ((string) $row['property'] !== '' ? ' · Inmueble ' . $row['property'] : '');
    $money = static fn($value): string => '$' . number_format((float) $value, 0, ',', '.');
    $groups = [];
    if (in_array('credito_solicitudes', $keys, true) && $pending['requests'] !== []) {
      $groups[] = ['key' => 'credito_solicitudes', 'label' => 'Solicitudes de crédito', 'hint' => 'Sin aprobar ni rechazar', 'icon' => 'request_quote', 'count' => count($pending['requests']), 'rows' => array_map(static fn(array $row): array => [
        'date' => date('d/m/Y', (int) $row['created_at']), 'title' => 'Crédito #' . $row['id'] . ' · ' . $row['owner_name'],
        'meta' => $money($row['requested_amount']) . ((int) $row['requested_term'] > 0 ? ' a ' . (int) $row['requested_term'] . ' meses' : '') . ' · ' . $row['purpose'] . ' · ' . $where($row),
        'badge' => $age((int) $row['created_at']), 'action' => ['type' => 'owner_credit_open', 'id' => (int) $row['id']], 'button' => 'Ver solicitud',
      ], $pending['requests'])];
    }
    if (in_array('credito_contabilidad', $keys, true) && $pending['accounting'] !== []) {
      $groups[] = ['key' => 'credito_contabilidad', 'label' => 'Créditos sin ingresar a contabilidad', 'hint' => 'Con la respuesta ya enviada', 'icon' => 'account_balance', 'count' => count($pending['accounting']), 'rows' => array_map(static fn(array $row): array => [
        'date' => date('d/m/Y', (int) $row['response_sent_at']), 'title' => 'Crédito #' . $row['id'] . ' · ' . $row['owner_name'],
        'meta' => $money($row['approved_amount']) . ' a ' . (int) $row['approved_term'] . ' meses · ' . $where($row),
        'badge' => $age((int) $row['response_sent_at']), 'action' => ['type' => 'owner_credit_detail', 'id' => (int) $row['id']], 'button' => 'Ver crédito',
      ], $pending['accounting'])];
    }
    return $groups;
  }

  /** Vencimientos de créditos para el calendario: las mismas filas de la alerta, con su plazo. @return list<array<string,mixed>> */
  private function adminDueOwnerCreditItems(array $settings, int $fromTs, int $toTs): array
  {
    $pending = $this->ownerCreditService()->pendingAttention();
    $items = [];
    $add = function (array $row, string $type, string $group, string $color, int $baseTs, int $days, string $title, string $description, string $view) use (&$items, $fromTs, $toTs): void {
      if ($baseTs <= 0) return;
      $dueTs = (int) strtotime('+' . $days . ' days', (int) strtotime(date('Y-m-d 00:00:00', $baseTs)));
      $calendarTs = $this->adminDueCalendarPlacementTimestamp($dueTs, $fromTs, $toTs);
      if ($calendarTs <= 0) return;
      $items[] = $this->adminDueEvent(['id' => $type . '-' . $row['id'], 'type' => $type, 'group' => $group, 'title' => $title, 'description' => $description, 'color' => $color,
        'base_ts' => $baseTs, 'due_ts' => $dueTs, 'calendar_ts' => $calendarTs, 'days_limit' => $days, 'case' => ['owner_credit_id' => (string) (int) $row['id'], 'owner_credit_view' => $view]]);
    };
    $money = static fn($value): string => '$' . number_format((float) $value, 0, ',', '.');
    foreach ($pending['requests'] as $row) {
      $add($row, 'credito_solicitud_pendiente', 'Solicitudes de crédito', '#4f46e5', (int) $row['created_at'], (int) $settings['creditos_solicitud_dias'],
        'Crédito #' . $row['id'] . ' sin decidir · ' . $row['owner_name'], 'Solicitud por ' . $money($row['requested_amount']) . ', contrato #' . $row['contract_code'] . '. Vence ' . (int) $settings['creditos_solicitud_dias'] . ' día(s) después de la solicitud.', 'open');
    }
    foreach ($pending['accounting'] as $row) {
      $add($row, 'credito_contabilidad_pendiente', 'Créditos sin ingresar a contabilidad', '#059669', (int) $row['response_sent_at'], (int) $settings['creditos_contabilidad_dias'],
        'Crédito #' . $row['id'] . ' sin ingresar a contabilidad · ' . $row['owner_name'], 'Aprobado por ' . $money($row['approved_amount']) . ', contrato #' . $row['contract_code'] . '. Vence ' . (int) $settings['creditos_contabilidad_dias'] . ' día(s) después de enviar la respuesta.', 'detail');
    }
    return $items;
  }

  /** Condiciones, documentos y contabilidad de un crédito aprobado, sin abrir el liquidador. */
  public function ajax_handler_owner_credit_detail(): void
  {
    $this->verifyCsrf();
    if (!$this->canViewOwnerCredits()) $this->jsonFail('No tienes permiso para ver créditos.');
    try {
      $id = (int) ($_POST['credit_id'] ?? 0);
      $service = $this->ownerCreditService();
      $service->ensureSchema();
      $work = $service->workspace($id);
      if (!in_array($work['credit']['status'], ['aprobado', 'pagado'], true)) throw new \DomainException('Las condiciones existen para créditos aprobados.');
      $this->jsonOk(['title' => 'Crédito #' . $id . ' · ' . OwnerCreditService::STATUSES[$work['credit']['status']], 'html' => (new OwnerCreditView())->detail($work, $this->canMarkOwnerCreditAccounting())]);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo abrir el crédito.');
    }
  }

  /** Quien gestiona créditos también puede marcarlos; el permiso propio es para contabilidad, que no los gestiona. */
  private function canMarkOwnerCreditAccounting(): bool
  {
    return $this->canUseDashboardAction('owner_credit_accounting') || $this->canUseDashboardAction('owner_credit_manage');
  }

  public function ajax_handler_owner_credit_accounting(): void
  {
    $this->verifyCsrf();
    if (!$this->canMarkOwnerCreditAccounting()) $this->jsonFail('Tu cargo no tiene permiso para marcar créditos en contabilidad.');
    try {
      $done = (string) ($_POST['done'] ?? '') === '1';
      $this->ownerCreditService()->setAccounting((int) ($_POST['credit_id'] ?? 0), $done, $this->ticketCompletionActor());
      $this->jsonOk(['message' => $done ? 'Crédito marcado como ingresado a contabilidad.' : 'Se quitó la marca de contabilidad.']);
    } catch (\Throwable $error) {
      $this->ownerCreditFail($error, 'No se pudo actualizar la marca de contabilidad.');
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
        foreach (['usury_auto', 'usury_annual', 'usury_updated_at', 'reference_rate', 'policy_discount', 'no_mandate_deduction', 'income_months', 'min_remaining_months', 'term_margin_months', 'max_term', 'usable_income_factor', 'min_coverage', 'require_promissory', 'notify_owner_request_email', 'notify_owner_request_whatsapp', 'notify_owner_email', 'notify_owner_whatsapp', 'notify_staff_whatsapp', 'whatsapp_unified_templates'] as $key) {
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
