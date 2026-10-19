<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\LeaseContracts\LeaseAnnexesService;
use SCM\Modules\LeaseContracts\LeaseContractCreateView;
use SCM\Modules\LeaseContracts\LeaseContractCreationService;
use SCM\Modules\LeaseContracts\LeaseContractsView;
use SCM\Support\StoredFileService;

/** Actividades contractuales › Contratos de arrendamiento: documentos, historiales y envío de anexos. */
trait HandlesLeaseContracts
{
  private function renderLeaseContractsPanel(): string
  {
    return (new LeaseContractsView())->renderPanel(
      $this->get_pending_controller()->getContratoBucketDefinitions(),
      $this->canUseDashboardAction('contract_annexes_send')
    );
  }

  public function ajax_handler_lease_contract_view(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('contratos_arrendamiento')) {
      $this->jsonFail('No tienes permiso para ver contratos de arrendamiento.');
    }
    $view = sanitize_key((string) ($_POST['view'] ?? ''));
    $service = new LeaseAnnexesService($this->db);
    $renderer = new LeaseContractsView();
    try {
      $contract = $service->contract((int) ($_POST['contract_id'] ?? 0));
      $code = trim((string) ($contract['contrato'] ?? $contract['_ID'] ?? ''));
      [$kicker, $html] = match ($view) {
        'documentos' => ['Documentación del contrato', $renderer->renderDocumentation($contract)],
        'ficha' => ['Ficha del inmueble', $renderer->renderProperty($contract, $service->property($contract))],
        'historial-contrato' => ['Historial del contrato', $renderer->renderContractHistory($service->history((int) $contract['_ID']))],
        'envio' => $this->canUseDashboardAction('contract_annexes_send')
          ? ['Enviar anexos del contrato', $renderer->renderSendForm($contract, $service->staff(), $service->annexRecords($contract))]
          : throw new \DomainException('No tienes permiso para enviar anexos de contratos.'),
        'crear' => $this->lease_contract_create_view($contract),
        'actas-copropiedad' => $this->canUseDashboardAction('contract_annexes_send')
          ? ['Reenviar actas de copropiedad', (new LeaseContractCreateView())->renderResendActs($contract, (new LeaseContractCreationService($this->db))->buildingActs($contract))]
          : throw new \DomainException('No tienes permiso para reenviar actas de contratos.'),
        'desistir' => $this->canUseDashboardAction('contract_desist')
          ? ['Marcar contrato desistido', $renderer->renderDesistForm($contract, $service->desistReasons())]
          : throw new \DomainException('No tienes permiso para marcar contratos como desistidos.'),
        default => throw new \DomainException('Vista no disponible.'),
      };
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
    $this->jsonOk([
      'kicker' => $kicker,
      'title' => 'Contrato #' . $code . ' · ' . trim((string) ($contract['direccion'] ?? '')),
      'html' => $html,
    ]);
  }

  public function ajax_handler_lease_contract_send(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('contratos_arrendamiento') || !$this->canUseDashboardAction('contract_annexes_send')) {
      $this->jsonFail('No tienes permiso para enviar anexos de contratos.');
    }
    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $input = [];
    foreach (['inicio_contrato', 'fin_contrato', 'registro_fotografico', 'id_acta_entrega', 'id_inventario', 'num_codeudores'] as $field) {
      $input[$field] = trim(sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? ''))));
    }
    foreach (['propietario', 'arrendatario', 'codeudor_1', 'codeudor_2', 'codeudor_3', 'codeudor_4'] as $person) {
      foreach (['', 'documento_', 'correo_', 'celular_'] as $prefix) {
        $input[$prefix . $person] = trim(sanitize_text_field(wp_unslash((string) ($_POST[$prefix . $person] ?? ''))));
      }
    }

    $leaseFile = null;
    try {
      $leaseFile = $this->lease_contract_store_upload();
      $result = (new LeaseAnnexesService($this->db))->send($contractId, $input, $leaseFile, $this->lease_contract_sender());
    } catch (\Throwable $error) {
      if ($leaseFile !== null && is_file($leaseFile['path'])) {
        @unlink($leaseFile['path']);
      }
      error_log('[lease_contract_send] #' . $contractId . ': ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudieron enviar los anexos. Intenta nuevamente.');
    }
    $queued = (array) ($result['queued'] ?? []);
    $this->jsonOk([
      'message' => sprintf('Anexos enviados. Correos: %d, WhatsApp: %d, avisos internos: %d.', (int) ($queued['email'] ?? 0), (int) ($queued['whatsapp'] ?? 0), (int) ($queued['internal'] ?? 0)),
      'html' => (new LeaseContractsView())->renderSendResult($result),
    ]);
  }

  /** @param array<string,mixed> $contract @return array{0:string,1:string} */
  private function lease_contract_create_view(array $contract): array
  {
    if (!$this->canUseDashboardAction('contract_create')) {
      throw new \DomainException('No tienes permiso para crear contratos de arrendamiento.');
    }
    if (!LeaseContractCreationService::isUnassigned($contract)) {
      throw new \DomainException('Este contrato ya tiene número asignado.');
    }
    $service = new LeaseContractCreationService($this->db);
    return ['Crear contrato de arrendamiento', (new LeaseContractCreateView())->render($contract, $service->prefill($contract), $service->options())];
  }

  public function ajax_handler_lease_contract_create(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('contratos_arrendamiento') || !$this->canUseDashboardAction('contract_create')) {
      $this->jsonFail('No tienes permiso para crear contratos de arrendamiento.');
    }
    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $clean = static function ($value) use (&$clean) {
      if (is_array($value)) {
        return array_map($clean, $value);
      }
      return trim(strip_tags(wp_unslash((string) $value)));
    };
    $input = [];
    foreach ($_POST as $key => $value) {
      if (is_string($key) && preg_match('/^[a-z0-9_]+$/', $key) === 1) {
        $input[$key] = $clean($value);
      }
    }
    try {
      $result = (new LeaseContractCreationService($this->db))->create($contractId, $input, $this->lease_contract_sender());
    } catch (\Throwable $error) {
      error_log('[lease_contract_create] #' . $contractId . ': ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo crear el contrato. Intenta nuevamente.');
    }
    $this->jsonOk([
      'message' => 'Contrato #' . $result['contrato'] . ' creado.',
      'html' => (new LeaseContractCreateView())->renderResult($result),
    ]);
  }

  public function ajax_handler_lease_contract_resend_acts(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('contratos_arrendamiento') || !$this->canUseDashboardAction('contract_annexes_send')) {
      $this->jsonFail('No tienes permiso para reenviar actas de contratos.');
    }
    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $targets = array_map(static fn($value): string => sanitize_key((string) $value), (array) ($_POST['targets'] ?? []));
    try {
      $result = (new LeaseContractCreationService($this->db))->resendBuildingActs($contractId, $targets, $this->lease_contract_sender());
    } catch (\Throwable $error) {
      error_log('[lease_contract_resend_acts] #' . $contractId . ': ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudieron reenviar las actas.');
    }
    $this->jsonOk(['message' => $result['message'] . ' Correos: ' . $result['email'] . ', WhatsApp: ' . $result['whatsapp'] . '.']);
  }

  public function ajax_handler_lease_contract_desist(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('contratos_arrendamiento') || !$this->canUseDashboardAction('contract_desist')) {
      $this->jsonFail('No tienes permiso para marcar contratos como desistidos.');
    }
    if ((string) ($_POST['confirmar'] ?? '') !== '1') {
      $this->jsonFail('Confirma que el contrato debe quedar como desistido.');
    }
    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $note = trim(strip_tags(wp_unslash((string) ($_POST['observacion'] ?? ''))));
    try {
      $result = (new LeaseAnnexesService($this->db))->desist(
        $contractId,
        trim(sanitize_text_field(wp_unslash((string) ($_POST['razon_desistido'] ?? '')))),
        mb_substr($note, 0, 1000),
        $this->lease_contract_sender()
      );
    } catch (\Throwable $error) {
      error_log('[lease_contract_desist] #' . $contractId . ': ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo marcar el contrato como desistido.');
    }
    $this->jsonOk(['message' => $result['message']]);
  }

  /** @return array{url:string,path:string}|null */
  private function lease_contract_store_upload(): ?array
  {
    $file = $_FILES['contrato_arrendamiento'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
      return null;
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
      throw new \DomainException('No se pudo recibir el contrato de arrendamiento. Intenta adjuntarlo de nuevo.');
    }
    if (!in_array(strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx'], true)) {
      throw new \DomainException('El contrato de arrendamiento debe ser PDF o Word.');
    }
    if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
      throw new \DomainException('El contrato de arrendamiento supera 10 MB.');
    }
    $files = StoredFileService::fromRuntime();
    $stored = $files->storeDocuments('contrato_arrendamiento', [], 1);
    $url = (string) ($stored[0]['archivo'] ?? '');
    parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
    $path = (string) ($files->pathFor((string) ($query['n'] ?? '')) ?? '');
    if ($url === '' || $path === '') {
      throw new \DomainException('El archivo del contrato no es válido. Verifica que sea un PDF o Word sin daños.');
    }
    return ['url' => $url, 'path' => $path];
  }

  /** @return array{employee_id:string,name:string,cargo:string,phone:string} */
  private function lease_contract_sender(): array
  {
    $employeeId = trim((string) $this->current_employee_id());
    $sender = ['employee_id' => $employeeId !== '' ? $employeeId : (string) Auth::userId(), 'name' => trim(Auth::user()) ?: 'SKC SuCasa Inmobiliaria', 'cargo' => '', 'phone' => ''];
    $table = $this->db->table('jet_cct_funcionarios');
    $cargoTable = $this->db->table('jet_cct_cargos');
    if (Auth::userId() > 0 && $this->table_exists($table)) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [Auth::userId()]);
      if (is_array($row)) {
        $sender['name'] = trim((string) ($row['nombre'] ?? '')) ?: $sender['name'];
        $sender['phone'] = trim((string) ($row['celular'] ?? ''));
        $cargoId = trim((string) ($row['id_cargo'] ?? ''));
        if ($cargoId !== '' && $this->table_exists($cargoTable)) {
          $sender['cargo'] = trim((string) $this->db->getVar("SELECT `nombre_cargo` FROM `{$cargoTable}` WHERE `_ID` = ? LIMIT 1", [$cargoId]));
        }
      }
    }
    return $sender;
  }
}
