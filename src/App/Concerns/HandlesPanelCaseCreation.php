<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\PublicTickets\PublicTicketsService;

trait HandlesPanelCaseCreation
{
  private function panelCaseService(): PublicTicketsService
  {
    $this->verifyCsrf();
    if (!Auth::isLoggedIn() || !$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para crear casos desde este panel.');
    }
    return new PublicTicketsService($this->db, ['app_secret' => SCM_APP_SECRET]);
  }

  public function ajax_handler_panel_case_options(): void
  {
    $this->jsonOk($this->panelCaseService()->panelCaseOptions());
  }

  public function ajax_handler_panel_case_read(): void
  {
    $this->verifyCsrf();
    try {
      $this->jsonOk(['case' => $this->panelCaseReadPayload((int) ($_POST['case_id'] ?? 0))]);
    } catch (\InvalidArgumentException $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  private function panelCaseReadPayload(int $id): array
  {
    if (!Auth::isLoggedIn()) throw new \InvalidArgumentException('Inicia sesión para consultar el caso.');
    $ticket = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ?', [$id]);
    $copies = array_column(\SCM\Support\InternalNotificationRecipients::contactsForAction($this->db, 'nuevo_caso_panel'), 'employee_id');
    $isCopy = ($ticket['medio'] ?? '') === 'Panel administrativo' && in_array((string) Auth::employeeId(), $copies, true);
    if (!$ticket || (!$this->canAccessDashboardTab('metricas') && (string) ($ticket['id_empleado'] ?? '') !== (string) Auth::employeeId() && !$isCopy)) {
      throw new \InvalidArgumentException('No tienes permiso para consultar este caso.');
    }
    $case = $this->adminDueNativeTicketCasePayload($id, $this->adminDueStatusBucket($ticket));
    if ($case === []) throw new \InvalidArgumentException('No se pudo cargar el detalle del caso.');
    return $case;
  }

  public function ajax_handler_panel_case_search(): void
  {
    $service = $this->panelCaseService();
    try {
      $this->jsonOk(['contracts' => $service->searchPanelContracts(trim((string) ($_POST['query'] ?? '')), (string) ($_POST['by'] ?? ''))]);
    } catch (\InvalidArgumentException $e) {
      $this->jsonFail($e->getMessage());
    }
  }

  public function ajax_handler_panel_case_analyze(): void
  {
    $service = $this->panelCaseService();
    try {
      if (!\SCM\Support\PanelCaseAi::availability()['enabled']) {
        throw new \InvalidArgumentException(\SCM\Support\PanelCaseAi::availability()['message']);
      }
      $id = $_POST['contract_id'] ?? '';
      $text = $_POST['source_text'] ?? '';
      if (!is_scalar($id) || !ctype_digit((string) $id) || !is_string($text)) throw new \InvalidArgumentException('Selecciona el contrato y revisa el contenido para analizar.');
      $contract = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_contratos_arrendamiento') . '` WHERE `_ID` = ? LIMIT 1', [(int) $id]);
      if (!$contract) throw new \InvalidArgumentException('Selecciona un contrato existente antes de analizar.');
      $ai = new \SCM\Support\PanelCaseAi();
      $images = $ai->images(is_array($_FILES['ai_images'] ?? null) ? $_FILES['ai_images'] : []);
      $options = $service->panelCaseOptions();
      // Validate before consuming quota or calling the provider. Never accept remote image URLs.
      $ai->requestPayload($text, $images, $contract, $options);
      $limiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
      if (!$limiter->consume('panel-case-ai:employee:' . Auth::employeeId(), 6, 300)
        || !$limiter->consume('panel-case-ai:global', 60, 3600)) {
        throw new \InvalidArgumentException('Se alcanzó el límite de análisis. Espera unos minutos o completa el caso manualmente.');
      }
      $draft = $ai->analyze($text, $images, $contract, $options);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->jsonFail($e->getMessage());
    }
    $this->jsonOk(['draft' => $draft, 'contract_id' => (string) $contract['_ID']]);
  }

  /**
   * "Crear nuevo caso" con tema Recibo de inmuebles: se crea como el recibo de Contratos (aviso previo de revisión antes de la desocupación y notificaciones).
   * @return array<string,mixed>
   */
  private function createPanelReceiptCase(PublicTicketsService $service): array
  {
    $options = $service->panelCaseOptions();
    $theme = trim((string) ($_POST['tema_ayuda'] ?? ''));
    $title = trim(strip_tags((string) wp_unslash($_POST['asunto'] ?? '')));
    $description = trim(strip_tags((string) wp_unslash($_POST['descripcion'] ?? '')));
    $employeeId = trim((string) ($_POST['id_empleado'] ?? ''));
    if (!in_array($theme, $options['themes'], true)) {
      throw new \InvalidArgumentException('Selecciona un tema válido.');
    }
    if ($title === '' || mb_strlen($title) > 200 || $description === '' || mb_strlen($description) > 10000) {
      throw new \InvalidArgumentException('Completa el título (máximo 200 caracteres) y la descripción (máximo 10.000).');
    }
    if (!in_array($employeeId, array_map('strval', array_column($options['employees'], 'employee_id')), true)) {
      throw new \InvalidArgumentException('Selecciona un responsable activo de los cargos habilitados en Configuración.');
    }
    $department = trim((string) ($_POST['departamento'] ?? ''));
    $documentTitles = array_map('strval', (array) ($_FILES['archivos']['name'] ?? []));
    $result = $this->createManualReceiptCase([
      'contract_pk' => trim((string) ($_POST['contract_id'] ?? '')),
      'id_empleado' => $employeeId,
      'asunto' => $title,
      'descripcion' => $description,
      'departamento' => in_array($department, $options['departments'], true) ? $department : '',
      'fecha_entrega_recibo' => trim((string) ($_POST['fecha_entrega_recibo'] ?? '')),
      'notify_tenant' => in_array('arrendatario', array_map('strval', (array) ($_POST['notify_roles'] ?? [])), true),
    ], $this->handleImageUploads('imagenes', 10), $this->handleDocumentUploads('archivos', $documentTitles, 10));
    if (($result['ok'] ?? '0') !== '1') {
      throw new \RuntimeException((string) ($result['message'] ?? 'No se pudo crear el recibo.'));
    }
    return [
      'ticket_id' => (int) $result['ticket_id'],
      'queued' => (int) ($result['emails_queued'] ?? 0),
      'whatsapp_queued' => (int) ($result['whatsapp_queued'] ?? 0),
      'notification_details' => array_values((array) ($result['whatsapp_details'] ?? [])),
      'message' => (string) $result['message'] . ' Se generó el aviso previo de revisión antes de la desocupación del inmueble.',
    ];
  }

  public function ajax_handler_panel_case_create(): void
  {
    $service = $this->panelCaseService();
    // Persist a receipt under a lock so double clicks and network retries return the same case.
    $requestId = (string) ($_POST['request_id'] ?? '');
    if (!preg_match('/^[a-f0-9-]{36}$/D', $requestId)) $this->jsonFail('Identificador de solicitud inválido. Abre nuevamente el formulario.');
    $directory = SCM_STORAGE_PATH . '/data/panel-case-receipts';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) $this->jsonFail('No se pudo iniciar la creación del caso.');
    $receipt = fopen($directory . '/' . hash('sha256', Auth::employeeId() . ':' . $requestId) . '.json', 'c+');
    if (!$receipt || !flock($receipt, LOCK_EX)) $this->jsonFail('No se pudo bloquear la solicitud. Intenta nuevamente.');
    try {
      $saved = json_decode((string) stream_get_contents($receipt), true);
      if (is_array($saved) && !empty($saved['ticket_id'])) {
        $result = $saved;
      } else {
        $result = $this->isReceiptTopic((string) ($_POST['tema_ayuda'] ?? ''))
          ? $this->createPanelReceiptCase($service)
          : $service->createPanelTicket($_POST);
        rewind($receipt);
        ftruncate($receipt, 0);
        fwrite($receipt, (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        fflush($receipt);
      }
    } catch (\PDOException $e) {
      error_log('[panel_case_create] ' . $e->getMessage());
      $error = 'No se pudo guardar el caso. Intenta nuevamente.';
    } catch (\InvalidArgumentException | \RuntimeException $e) {
      $error = $e->getMessage();
    } finally {
      flock($receipt, LOCK_UN);
      fclose($receipt);
    }
    if (isset($error)) $this->jsonFail($error);
    $this->clearDashboardPerformanceCache('dashboard-metrics-v2');
    if (function_exists('apcu_delete')) apcu_delete('scm_dashboard_metrics_v2_' . md5((string) SCM_ROOT));
    $this->jsonOk($result);
  }
}
